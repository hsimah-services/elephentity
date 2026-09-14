<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Verification;

use Closure;
use Eleph\Runtime\Conformance\Severity;
use Eleph\Runtime\Conformance\Signal;
use Eleph\Runtime\Conformance\Verifier;
use Eleph\WPGraphQL\Manifest\Manifest;

/**
 * Proves the GraphQL surface and the generated classes still agree.
 *
 * The plugin layer rests entirely on one convention — a spec field named `title` is a
 * GraphQL field `title` resolved by `getTitle()` — and a convention that is only ever
 * asserted in prose is one that eventually breaks. Both ends are generated from the
 * same spec, so they *should* agree; this is what turns "should" into a build failure.
 *
 * It checks the classes on disk rather than the IR, so it catches what the IR cannot
 * see: generated code that was never regenerated after the spec changed. And it reads
 * its own manifest off the tree — the one `eleph generate` last wrote — rather than one
 * rebuilt in memory, so it also catches the manifest itself having drifted from the
 * classes the PHP builder wrote, which two builders on independent tag lines can do
 * without either one being wrong on its own.
 */
final readonly class WPGraphQLVerifier implements Verifier
{
    public function __construct(private Manifest $manifest)
    {
    }

    public function verify(Closure $classFor): array
    {
        $signals = [];

        foreach ($this->manifest->objects as $object) {
            $class = $classFor($object->entity);

            if (!class_exists($class)) {
                $signals[] = new Signal(Severity::Error, sprintf(
                    '%s is exposed as a GraphQL type but %s does not exist. Run `eleph generate`.',
                    $object->entity,
                    $class,
                ));

                continue;
            }

            foreach ($object->fields as $field) {
                if (!method_exists($class, $field->accessor)) {
                    $signals[] = new Signal(Severity::Error, sprintf(
                        '%s.%s resolves via %s::%s(), which does not exist.',
                        $object->name,
                        $field->name,
                        $class,
                        $field->accessor,
                    ));
                }
            }

            foreach ($object->connections as $connection) {
                if (!method_exists($class, $connection->accessor)) {
                    $signals[] = new Signal(Severity::Error, sprintf(
                        '%s.%s resolves via %s::%s(), which does not exist.',
                        $object->name,
                        $connection->name,
                        $class,
                        $connection->accessor,
                    ));
                }
            }
        }

        return $signals;
    }
}
