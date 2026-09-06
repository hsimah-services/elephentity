<?php

declare(strict_types=1);

namespace Eleph\Codegen;

/**
 * Everything the generator needs that is configuration rather than specification.
 *
 * Namespaces live here and never in the spec, so renaming a namespace is a config
 * change rather than an edit to every entity yaml.
 */
final readonly class GeneratorConfig
{
    public function __construct(
        /** Root namespace for generated code, e.g. App\Elephentity. */
        public string $rootNamespace,
        /** Where generated files are written. Entirely machine-owned. */
        public string $outputDirectory,
        /**
         * Namespace holding user-written value classes such as Money.
         *
         * The generator does not emit these — a value object has behaviour no
         * generator can invent — but it must name them in type hints.
         */
        public string $typeNamespace,
    ) {
    }

    public function namespaceFor(string ...$segments): string
    {
        return implode('\\', [trim($this->rootNamespace, '\\'), ...$segments]);
    }
}
