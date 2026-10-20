<?php

declare(strict_types=1);

namespace Eleph\Codegen;

/**
 * Everything a target is given that is not the IR.
 *
 * `config` is deliberately opaque. What `typeNamespace` means is a property of the PHP
 * target, so the core validates that a target was configured at all and leaves the
 * rest to the target to read and reject. If the core ever learns what one of these
 * keys means, the coupling has only moved house.
 */
final readonly class TargetRequest
{
    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        public string $outputDirectory,
        public array $config,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function of(string $outputDirectory, array $config): self
    {
        return new self($outputDirectory, $config);
    }
}
