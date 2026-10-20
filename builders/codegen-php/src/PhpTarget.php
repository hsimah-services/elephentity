<?php

declare(strict_types=1);

namespace Eleph\Gen\Php;

use Eleph\Gen\Php\Generator\BridgeGenerator;
use Eleph\Gen\Php\Generator\CatalogueGenerator;
use Eleph\Gen\Php\Generator\ClassMapGenerator;
use Eleph\Gen\Php\Generator\ContextGenerator;
use Eleph\Gen\Php\Generator\ContractGenerator;
use Eleph\Gen\Php\Generator\DeleterGenerator;
use Eleph\Gen\Php\Generator\EntityGenerator;
use Eleph\Gen\Php\Generator\EnumGenerator;
use Eleph\Gen\Php\Generator\FinderGenerator;
use Eleph\Gen\Php\Generator\HydratorGenerator;
use Eleph\Gen\Php\Generator\InputGenerator;
use Eleph\Gen\Php\Generator\MutatorGenerator;
use Eleph\Gen\Php\Generator\PatternGenerator;
use Eleph\Gen\Php\Generator\WiringGenerator;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Naming\TypeMapper;

/**
 * The PHP target: a compiled schema in, the complete set of PHP files out.
 *
 * A pure function of the schema and its config: same input, same output, every time.
 * Nothing here reads the application's source, which is why a missing handler is a
 * boot failure rather than a generation failure — the target has no opinion about
 * what exists.
 *
 * It returns file bodies and never writes them; signing and writing belong to the
 * core, so that one Signer decides how every generated file in every language is
 * locked. See docs/PLAN.md §15.
 */
final readonly class PhpTarget
{
    public const NAME = 'php';

    /** How this language comments, for whoever renders the signed header. */
    public const HEADER_STYLE = 'php';

    public function generate(TargetRequest $request, Schema $schema): TargetResponse
    {
        $problems = PhpConfig::problemsIn($request->config);

        if ([] !== $problems) {
            return TargetResponse::failed($problems, self::HEADER_STYLE);
        }

        $config = PhpConfig::from($request->config);

        $names = new Names($config);
        $emitter = new Emitter($names);
        $types = new TypeMapper($schema, $names);

        $entities = new EntityGenerator($schema, $names, $types, $emitter);
        $mutators = new MutatorGenerator($schema, $names, $types, $emitter);
        $finders = new FinderGenerator($schema, $names, $types, $emitter);
        $contracts = new ContractGenerator($schema, $names, $types, $emitter);
        $contexts = new ContextGenerator($names, $types, $emitter);
        $enums = new EnumGenerator($names, $emitter);
        $bridges = new BridgeGenerator($names, $types, $emitter);
        $hydrators = new HydratorGenerator($schema, $names, $types, $emitter);
        $deleters = new DeleterGenerator($schema, $names, $emitter);
        $inputs = new InputGenerator($schema, $names, $types, $emitter);
        $patterns = new PatternGenerator($schema, $names, $types, $emitter);

        /** @var list<GeneratedFile> $files */
        $files = $enums->generate($schema);

        foreach ($contracts->processors() as $file) {
            $files[] = $file;
        }
        foreach ($contracts->patternPolicies() as $file) {
            $files[] = $file;
        }

        foreach ($patterns->generate() as $file) {
            $files[] = $file;
        }

        foreach ($schema->entities as $entity) {
            $files[] = $entities->generate($entity);
            $files[] = $mutators->generate($entity);
            $files[] = $contexts->mutationContext($entity);
            $files[] = $contexts->mutationContext($entity, true);
            $files[] = $hydrators->generate($entity);
            $files[] = $deleters->generate($entity);
            $files[] = $inputs->generate($entity);

            foreach ($bridges->generate($entity) as $file) {
                $files[] = $file;
            }

            $finder = $finders->generate($entity);

            if (null !== $finder) {
                $files[] = $finder;
            }

            foreach ($contexts->actionContexts($entity) as $file) {
                $files[] = $file;
            }
            foreach ($contexts->actionArguments($entity) as $file) {
                $files[] = $file;
            }
            $files[] = $contexts->writeContext($entity);

            foreach ($contracts->generate($entity) as $file) {
                $files[] = $file;
            }
        }

        $files[] = (new CatalogueGenerator($schema, $names, $emitter))->generate();
        $files[] = (new WiringGenerator($schema, $names, $emitter))->generate();

        // Last, and over the finished list: it is an index of everything above it.
        $files[] = (new ClassMapGenerator($schema, $config, $names))->generate($files);

        usort($files, static fn (GeneratedFile $a, GeneratedFile $b) => strcmp($a->relativePath, $b->relativePath));

        return TargetResponse::ok($files, self::HEADER_STYLE, ['php']);
    }
}
