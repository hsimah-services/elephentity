<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\Naming\Names;
use Eleph\Codegen\Php\PhpConfig;
use Eleph\Schema\Ir\Schema;

/**
 * Emits the tree's own index: what it generated, and where each class lives.
 *
 * The conformance gate has to turn a spec name like `Post` into `App\Entity\Post\Post`
 * and load the file holding it. It used to do that by calling `Names` — the generator's
 * own naming code — which meant the gate could only run somewhere the PHP generator was
 * on the classpath. That is exactly the coupling a builder is supposed to not have: a
 * TypeScript builder could never satisfy it.
 *
 * Writing the answer into the tree instead makes the generated output the source of
 * truth, which is stronger than sharing a class rather than weaker. A map that
 * disagreed with the files beside it would be a map the drift gate rewrites, because it
 * is generated and signed like everything else here.
 *
 * `classes` is complete, so a consumer can autoload the tree without knowing that the
 * namespace below the root mirrors the directory. Nothing outside has to restate that
 * convention, which is the second place it used to be written down.
 */
final readonly class ClassMapGenerator
{
    public const PATH = 'class-map.php';

    public function __construct(
        private Schema $schema,
        private PhpConfig $config,
        private Names $names,
    ) {
    }

    /**
     * @param list<GeneratedFile> $files Everything else this target produced.
     */
    public function generate(array $files): GeneratedFile
    {
        return new GeneratedFile(self::PATH, sprintf(
            <<<'PHP'
                /**
                 * What this tree contains, and where.
                 *
                 * `entities` maps a spec name to the class representing it; `classes` maps
                 * every generated class to its file, relative to this one's directory.
                 *
                 * Written for tools that did not generate the tree and should not have to
                 * know how it was named — `eleph check` resolves a GraphQL type back to a
                 * class through here rather than by reimplementing the generator's rules.
                 */
                return [
                    'entities' => [
                %s
                    ],
                    'classes' => [
                %s
                    ],
                ];

                PHP,
            $this->render($this->entities()),
            $this->render($this->classes($files)),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function entities(): array
    {
        $entities = [];

        foreach ($this->schema->entities as $entity) {
            $entities[$entity->name] = $this->names->entity($entity);
        }

        ksort($entities);

        return $entities;
    }

    /**
     * Every generated class, addressed by the path it was written to.
     *
     * The inverse of `Names::pathFor()`, and safe to do by string surgery because
     * `pathFor()` is the only thing that ever produced these paths.
     *
     * @param list<GeneratedFile> $files
     *
     * @return array<string, string>
     */
    private function classes(array $files): array
    {
        $root = trim($this->config->rootNamespace, '\\');
        $classes = [];

        foreach ($files as $file) {
            $relative = substr($file->relativePath, 0, -strlen('.php'));
            $classes[$root . '\\' . str_replace('/', '\\', $relative)] = $file->relativePath;
        }

        ksort($classes);

        return $classes;
    }

    /**
     * @param array<string, string> $entries
     */
    private function render(array $entries): string
    {
        $lines = [];

        foreach ($entries as $key => $value) {
            $lines[] = sprintf('        %s => %s,', var_export($key, true), var_export($value, true));
        }

        return implode("\n", $lines);
    }
}
