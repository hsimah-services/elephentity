<?php

declare(strict_types=1);

namespace PheFr\WPGraphQL\Conformance;

use Closure;
use PheFr\WPGraphQL\Manifest\Manifest;

/**
 * Proves the GraphQL surface and the generated entities agree.
 *
 * The plugin layer rests entirely on one convention — a spec field named `title` is a
 * GraphQL field `title` resolved by `getTitle()` — and a convention that is only ever
 * asserted in prose is one that eventually breaks. Both ends are generated from the
 * same spec, so they *should* agree; this is the gate that turns "should" into a build
 * failure.
 *
 * It checks the classes on disk rather than the IR, so it catches what the IR cannot
 * see: generated code that was never regenerated after the spec changed.
 */
final readonly class ConformanceChecker
{
    /**
     * @param Closure(string): string $classFor Entity name => fully-qualified class.
     */
    public function __construct(
        private Manifest $manifest,
        private Closure $classFor,
    ) {
    }

    /**
     * @return list<string> Empty when the surface conforms.
     */
    public function check(): array
    {
        $problems = [];

        foreach ($this->manifest->objects as $object) {
            $class = ($this->classFor)($object->entity);

            if (!class_exists($class)) {
                $problems[] = sprintf(
                    '%s is exposed as a GraphQL type but %s does not exist. Run `phefr generate`.',
                    $object->entity,
                    $class,
                );

                continue;
            }

            foreach ($object->fields as $field) {
                if (!method_exists($class, $field->accessor)) {
                    $problems[] = sprintf(
                        '%s.%s resolves via %s::%s(), which does not exist.',
                        $object->name,
                        $field->name,
                        $class,
                        $field->accessor,
                    );
                }
            }

            foreach ($object->connections as $connection) {
                if (!method_exists($class, $connection->accessor)) {
                    $problems[] = sprintf(
                        '%s.%s resolves via %s::%s(), which does not exist.',
                        $object->name,
                        $connection->name,
                        $class,
                        $connection->accessor,
                    );
                }
            }
        }

        return $problems;
    }
}
