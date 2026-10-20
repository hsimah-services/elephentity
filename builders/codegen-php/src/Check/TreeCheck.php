<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Check;

use Closure;
use Eleph\Runtime\Conformance\Severity;
use Eleph\Runtime\Conformance\Signal;
use Eleph\Runtime\Conformance\Verifier;
use RuntimeException;
use Throwable;

/**
 * Proves a generated PHP tree is internally coherent.
 *
 * Distinct from `eleph generate --check`, which asks whether the tree matches the spec. This
 * asks whether the tree agrees with *itself*: every integration ships a verifier that knows what
 * coherence means for its own surface ("every GraphQL field resolves to a method that exists,"
 * for WPGraphQL), and this walks the tree collecting what they find.
 *
 * A tree with no verifiers is silent and passes, which is correct for a project with no
 * integrations: nothing to check is not a failure to check it. What is *not* allowed to pass is a
 * tree that cannot be read at all, so every way of failing to check becomes an error signal
 * rather than an exception or a quiet success.
 */
final class TreeCheck
{
    /**
     * @return list<Signal>
     */
    public static function run(string $tree): array
    {
        try {
            $classes = ClassMap::load($tree);
        } catch (RuntimeException $exception) {
            return [new Signal(Severity::Error, $exception->getMessage())];
        }

        $classes->autoload();

        $signals = [];

        foreach (glob(rtrim($tree, '/') . '/*/verify.php') ?: [] as $file) {
            foreach (self::runVerifier($file, $classes->classFor(...)) as $signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * @param Closure(string): string $classFor
     *
     * @return list<Signal>
     */
    private static function runVerifier(string $file, Closure $classFor): array
    {
        try {
            /** @var mixed $verifier */
            $verifier = require $file;
        } catch (Throwable $exception) {
            return [new Signal(
                Severity::Error,
                sprintf('%s could not be loaded: %s. Run `eleph generate`.', $file, $exception->getMessage()),
            )];
        }

        if (!$verifier instanceof Verifier) {
            return [new Signal(
                Severity::Error,
                sprintf('%s did not return a verifier. Run `eleph generate`.', $file),
            )];
        }

        return $verifier->verify($classFor);
    }
}
