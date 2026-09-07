<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\Generator\BridgeGenerator;
use Eleph\Codegen\Php\Generator\CatalogueGenerator;
use Eleph\Codegen\Php\Generator\ContextGenerator;
use Eleph\Codegen\Php\Generator\ContractGenerator;
use Eleph\Codegen\Php\Generator\DeleterGenerator;
use Eleph\Codegen\Php\Generator\EntityGenerator;
use Eleph\Codegen\Php\Generator\EnumGenerator;
use Eleph\Codegen\Php\Generator\FinderGenerator;
use Eleph\Codegen\Php\Generator\HydratorGenerator;
use Eleph\Codegen\Php\Generator\InputGenerator;
use Eleph\Codegen\Php\Generator\MutatorGenerator;
use Eleph\Codegen\Php\Naming\Emitter;
use Eleph\Codegen\Php\Naming\Names;
use Eleph\Codegen\Php\Naming\TypeMapper;
use Eleph\Codegen\Signing\HeaderStyle;
use Eleph\Codegen\Target;
use Eleph\Codegen\TargetRequest;
use Eleph\Codegen\TargetResponse;
use Eleph\Schema\Ir\Schema;

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
final readonly class PhpTarget implements Target
{
    public const NAME = 'php';

    public function name(): string
    {
        return self::NAME;
    }

    public function generate(TargetRequest $request, Schema $schema): TargetResponse
    {
        $problems = PhpConfig::problemsIn($request->config);

        if ([] !== $problems) {
            return TargetResponse::failed($problems, HeaderStyle::Php);
        }

        $config = PhpConfig::from($request->config);

        $names = new Names($config);
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
        $deleters = new DeleterGenerator($schema, $names, $emitter);
        $inputs = new InputGenerator($schema, $names, $types, $emitter);

        $files = $enums->generate($schema);

        foreach ($contracts->processors() as $file) {
            $files[] = $file;
        }

        foreach ($schema->entities as $entity) {
            $files[] = $entities->generate($entity);
            $files[] = $mutators->generate($entity);
            $files[] = $contexts->mutationContext($entity);
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

            foreach ($contracts->generate($entity) as $file) {
                $files[] = $file;
            }
        }

        $files[] = (new CatalogueGenerator($schema, $names, $emitter))->generate();

        usort($files, static fn (GeneratedFile $a, GeneratedFile $b) => strcmp($a->relativePath, $b->relativePath));

        return TargetResponse::ok($files, HeaderStyle::Php);
    }
}
