<?php

declare(strict_types=1);

namespace PheFr\Codegen;

use PheFr\Codegen\Generator\BridgeGenerator;
use PheFr\Codegen\Generator\ContextGenerator;
use PheFr\Codegen\Generator\ContractGenerator;
use PheFr\Codegen\Generator\EntityGenerator;
use PheFr\Codegen\Generator\EnumGenerator;
use PheFr\Codegen\Generator\FinderGenerator;
use PheFr\Codegen\Generator\HydratorGenerator;
use PheFr\Codegen\Generator\MutatorGenerator;
use PheFr\Codegen\Naming\Emitter;
use PheFr\Codegen\Naming\Names;
use PheFr\Codegen\Naming\TypeMapper;
use PheFr\Schema\Ir\Schema;

/**
 * Turns a compiled schema into the complete set of files the generator owns.
 *
 * A pure function of the schema: same input, same output, every time. Nothing here
 * reads the application's source, which is why a missing handler is a boot failure
 * rather than a generation failure — codegen has no opinion about what exists.
 */
final readonly class Codegen
{
    public function __construct(private GeneratorConfig $config)
    {
    }

    /**
     * @return list<GeneratedFile>
     */
    public function generate(Schema $schema): array
    {
        $names = new Names($this->config);
        $emitter = new Emitter($names);
        $types = new TypeMapper($schema, $names);

        $entities = new EntityGenerator($schema, $names, $types, $emitter);
        $mutators = new MutatorGenerator($names, $types, $emitter);
        $finders = new FinderGenerator($schema, $names, $types, $emitter);
        $contracts = new ContractGenerator($schema, $names, $types, $emitter);
        $contexts = new ContextGenerator($names, $types, $emitter);
        $enums = new EnumGenerator($names, $emitter);
        $bridges = new BridgeGenerator($names, $types, $emitter);
        $hydrators = new HydratorGenerator($schema, $names, $types, $emitter);

        $files = $enums->generate($schema);

        foreach ($contracts->processors() as $file) {
            $files[] = $file;
        }

        foreach ($schema->entities as $entity) {
            $files[] = $entities->generate($entity);
            $files[] = $mutators->generate($entity);
            $files[] = $contexts->mutationContext($entity);
            $files[] = $hydrators->generate($entity);

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

            foreach ($contracts->generate($entity) as $file) {
                $files[] = $file;
            }
        }

        usort($files, static fn (GeneratedFile $a, GeneratedFile $b) => strcmp($a->relativePath, $b->relativePath));

        return $files;
    }
}
