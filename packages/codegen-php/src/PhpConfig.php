<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php;

use LogicException;

/**
 * Everything the PHP target needs that is configuration rather than specification.
 *
 * Namespaces live here and never in the spec, so renaming a namespace is a config
 * change rather than an edit to every entity yaml.
 *
 * The core hands this target an opaque array and has no idea what `typeNamespace`
 * means — reading and rejecting it is the target's job, which is what keeps the core
 * free of any one language's notion of configuration.
 */
final readonly class PhpConfig
{
    private function __construct(
        /** Root namespace for generated code, e.g. App\Elephentity. */
        public string $rootNamespace,
        /**
         * Namespace holding user-written value classes such as Money.
         *
         * The target does not emit these — a value object has behaviour no generator
         * can invent — but it must name them in type hints.
         */
        public string $typeNamespace,
    ) {
    }

    /**
     * Every problem with a config block, reported together.
     *
     * Separate from `from()` so that a caller can decide what to do with the problems
     * without a half-built config existing; running the build once per missing key is
     * exactly what the accumulate-and-report convention exists to prevent.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function problemsIn(array $config): array
    {
        $problems = [];

        foreach (['namespace', 'typeNamespace'] as $key) {
            $value = $config[$key] ?? null;

            if (!is_string($value) || '' === $value) {
                $problems[] = sprintf('The php target needs "%s" set to a non-empty string.', $key);
            }
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function from(array $config): self
    {
        $namespace = $config['namespace'] ?? null;
        $typeNamespace = $config['typeNamespace'] ?? null;

        if (!is_string($namespace) || !is_string($typeNamespace)) {
            throw new LogicException('PhpConfig::from() called on a config problemsIn() rejects.');
        }

        return new self($namespace, $typeNamespace);
    }

    public function namespaceFor(string ...$segments): string
    {
        return implode('\\', [trim($this->rootNamespace, '\\'), ...$segments]);
    }
}
