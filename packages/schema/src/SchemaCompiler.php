<?php

declare(strict_types=1);

namespace Eleph\Schema;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\Integration\IntegrationResolver;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Ir\ConfigType;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Origin;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Ir\StorageDefinition;
use Eleph\Schema\Ir\TypeDefinition;
use Eleph\Schema\Pattern\ConfigResolver;
use Eleph\Schema\Pattern\PatternDefinition;
use Eleph\Schema\Pattern\PatternResolver;
use Eleph\Schema\Pattern\SectionMerger;
use Eleph\Schema\Spec\ParsedSections;
use Eleph\Schema\Spec\RawSpec;
use Eleph\Schema\Spec\SchemaValidator;
use Eleph\Schema\Spec\SectionParser;
use Eleph\Schema\Spec\SpecKind;
use Eleph\Schema\Spec\SpecLoader;

/**
 * Compiles a directory of specs into the IR.
 *
 * Errors accumulate rather than aborting, so one run reports everything wrong with the
 * specs. The two exceptions are staged: nothing is resolved while any document is
 * malformed, and nothing is semantically checked while resolution is failing, because
 * later stages would only produce noise derived from the earlier failure.
 */
final readonly class SchemaCompiler
{
    public function __construct(
        private SpecLoader $loader = new SpecLoader(),
        private SectionParser $parser = new SectionParser(),
        private PatternResolver $resolver = new PatternResolver(),
        private SectionMerger $merger = new SectionMerger(),
        private ConfigResolver $config = new ConfigResolver(),
        /**
         * Empty by default: the compiler knows of no integration until a composition
         * root tells it which packages are installed.
         */
        private IntegrationRegistry $integrations = new IntegrationRegistry(),
    ) {
    }

    public function compile(SpecSource $source): CompilationResult
    {
        $loaded = $this->loader->load($source);
        $errors = $loaded['errors'];

        $validator = new SchemaValidator();

        foreach ($loaded['specs'] as $spec) {
            foreach ($validator->validate($spec) as $error) {
                $errors[] = $error;
            }
        }

        if ([] !== $errors) {
            return CompilationResult::failure($errors);
        }

        $project = $this->buildProject($loaded['specs'], $source, $errors);

        if (null === $project) {
            return CompilationResult::failure($errors);
        }

        $types = $this->buildTypes($loaded['specs'], $errors);
        $patterns = $this->buildPatterns($loaded['specs'], $errors);
        $entities = $this->buildEntities($project, $loaded['specs'], $patterns, $errors);

        if ([] !== $errors) {
            return CompilationResult::failure($errors);
        }

        $schema = new Schema($project, $entities, $types);

        $semantic = (new SemanticValidator())->validate($schema);

        return [] === $semantic
            ? CompilationResult::success($schema)
            : CompilationResult::failure($semantic);
    }

    /**
     * @param list<RawSpec>   $specs
     * @param list<SpecError> $errors
     *
     * @return array<string, TypeDefinition>
     */
    private function buildTypes(array $specs, array &$errors): array
    {
        $types = [];

        foreach ($specs as $spec) {
            if (SpecKind::Type !== $spec->kind) {
                continue;
            }

            $reader = $spec->reader();
            $name = $spec->name();

            if (isset($types[$name])) {
                $errors[] = new SpecError(
                    'type.duplicate',
                    sprintf('Type "%s" is already declared in %s.', $name, $types[$name]->sourceFile),
                    $spec->file,
                );

                continue;
            }

            $types[$name] = new TypeDefinition(
                name: $name,
                primitive: Primitive::from($reader->string('primitive')),
                sourceFile: $spec->file,
                description: $reader->optionalString('description'),
                hasProcessors: $reader->bool('processors'),
                values: $reader->has('values') ? $reader->stringList('values') : null,
            );
        }

        return $types;
    }

    /**
     * @param list<RawSpec>   $specs
     * @param list<SpecError> $errors
     *
     * @return array<string, PatternDefinition>
     */
    private function buildPatterns(array $specs, array &$errors): array
    {
        $patterns = [];

        foreach ($specs as $spec) {
            if (SpecKind::Pattern !== $spec->kind) {
                continue;
            }

            $reader = $spec->reader();
            $name = $spec->name();

            if (isset($patterns[$name])) {
                $errors[] = new SpecError(
                    'pattern.duplicate',
                    sprintf('Pattern "%s" is already declared in %s.', $name, $patterns[$name]->sourceFile),
                    $spec->file,
                );

                continue;
            }

            $storage = [];

            foreach ($reader->reader('storage')?->all() ?? [] as $key => $value) {
                if (is_string($value)) {
                    $storage[$key] = $value;
                }
            }

            $patterns[$name] = new PatternDefinition(
                name: $name,
                sourceFile: $spec->file,
                sections: $this->parser->parse($reader, Origin::pattern($name, $spec->file)),
                uses: $reader->stringList('use'),
                requiresDriver: $reader->reader('requires')?->optionalString('driver'),
                storage: $storage,
                config: $this->configParameters($reader),
                description: $reader->optionalString('description'),
            );
        }

        return $patterns;
    }

    /**
     * @param list<RawSpec>   $specs
     * @param list<SpecError> $errors
     */
    private function buildProject(array $specs, SpecSource $source, array &$errors): ?ProjectDefinition
    {
        foreach ($specs as $spec) {
            if (SpecKind::Project !== $spec->kind) {
                continue;
            }

            $reader = $spec->reader();
            $storage = $reader->reader('storage');

            return new ProjectDefinition(
                name: $spec->name(),
                driver: (string) $storage?->string('driver'),
                sourceFile: $spec->file,
                integrations: (new IntegrationResolver($this->integrations))->forProject(
                    $reader->reader('integrations'),
                    $spec->file,
                    $errors,
                ),
                tablePrefix: $storage?->optionalString('tablePrefix') ?? '',
                description: $reader->optionalString('description'),
            );
        }

        // Without one there is no driver, so nothing downstream can be resolved.
        $errors[] = new SpecError(
            'project.missing',
            sprintf(
                'No project.yml in %s. Every project needs one; it declares the storage driver.',
                $source->root,
            ),
            $source->root,
        );

        return null;
    }

    /**
     * @return array<string, ConfigParameter>
     */
    private function configParameters(\Eleph\Schema\Spec\SpecReader $reader): array
    {
        $parameters = [];

        foreach ($reader->readers('config') as $name => $declaration) {
            $type = ConfigType::from($declaration->string('type'));
            $of = $declaration->optionalString('of');

            $parameters[$name] = new ConfigParameter(
                name: $name,
                type: $type,
                description: $declaration->optionalString('description'),
                of: null === $of ? null : ConfigType::from($of),
                values: $declaration->has('values') ? $declaration->stringList('values') : null,
                nullable: $declaration->bool('nullable'),
                default: $declaration->raw('default'),
                hasDefault: $declaration->has('default'),
            );
        }

        return $parameters;
    }

    /**
     * @param list<RawSpec>                    $specs
     * @param array<string, PatternDefinition> $patterns
     * @param list<SpecError>                  $errors
     *
     * @return array<string, EntityDefinition>
     */
    private function buildEntities(
        ProjectDefinition $project,
        array $specs,
        array $patterns,
        array &$errors,
    ): array {
        $entities = [];

        foreach ($specs as $spec) {
            if (SpecKind::Entity !== $spec->kind) {
                continue;
            }

            $reader = $spec->reader();
            $name = $spec->name();

            if (isset($entities[$name])) {
                $errors[] = new SpecError(
                    'entity.duplicate',
                    sprintf('Entity "%s" is already declared in %s.', $name, $entities[$name]->sourceFile),
                    $spec->file,
                );

                continue;
            }

            $uses = $reader->stringList('use');

            $expansion = $this->resolver->expand($patterns, $uses, $spec->file);

            foreach ($expansion['errors'] as $error) {
                $errors[] = $error;
            }

            $storageReader = $reader->reader('storage');
            $driver = $project->driver;

            /** @var list<ParsedSections> $contributions */
            $contributions = [];

            /** @var list<PatternDefinition> $applied */
            $applied = [];

            // The project prefix is applied here, so everything downstream — conflict
            // detection, DDL, queries — sees one resolved table name.
            $storage = [
                'driver' => $driver,
                'table' => $project->tablePrefix . (string) $storageReader?->string('table'),
            ];

            $handle = $storageReader?->optionalString('handle');

            if (null !== $handle) {
                $storage['handle'] = $handle;
            }

            foreach ($expansion['patterns'] as $pattern) {
                if (null !== $pattern->requiresDriver && $pattern->requiresDriver !== $driver) {
                    $errors[] = new SpecError(
                        'pattern.driverMismatch',
                        sprintf(
                            'Pattern "%s" requires driver "%s" but %s uses "%s".',
                            $pattern->name,
                            $pattern->requiresDriver,
                            $name,
                            $driver,
                        ),
                        $spec->file,
                    );

                    continue;
                }

                foreach ($pattern->storage as $key => $value) {
                    if (isset($storage[$key])) {
                        $errors[] = new SpecError(
                            'pattern.collision',
                            sprintf(
                                '%s declares storage.%s from both the entity spec and pattern %s. Patterns are sealed.',
                                $name,
                                $key,
                                $pattern->name,
                            ),
                            $spec->file,
                        );

                        continue;
                    }

                    $storage[$key] = $value;
                }

                $contributions[] = $pattern->sections;
                $applied[] = $pattern;
            }

            $contributions[] = $this->parser->parse($reader, Origin::entity($spec->file));

            $configuration = $this->config->resolve(
                $applied,
                $reader->reader('configure'),
                $name,
                $spec->file,
                $errors,
            );

            $integrations = (new IntegrationResolver($this->integrations))->forEntity(
                $reader->reader('integrations'),
                $project->integrations,
                $name,
                $spec->file,
                $errors,
            );

            $merged = $this->merger->merge($contributions, $name, $spec->file);

            foreach ($merged['errors'] as $error) {
                $errors[] = $error;
            }

            $sections = $merged['sections'];

            $entities[$name] = new EntityDefinition(
                name: $name,
                storage: new StorageDefinition(
                    driver: $storage['driver'],
                    table: $storage['table'],
                    handle: $storage['handle'] ?? null,
                ),
                sourceFile: $spec->file,
                description: $reader->optionalString('description'),
                uses: $uses,
                fields: $sections->fields,
                edges: $sections->edges,
                queries: $sections->queries,
                actions: $sections->actions,
                triggers: $sections->triggers,
                config: $configuration,
                integrations: $integrations,
            );
        }

        return $entities;
    }
}
