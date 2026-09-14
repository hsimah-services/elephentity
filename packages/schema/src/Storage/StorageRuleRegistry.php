<?php

declare(strict_types=1);

namespace Eleph\Schema\Storage;

/**
 * The storage rules available to a project, one set per driver.
 *
 * Assembled by the composition root — the CLI, which pools `provides.storage` from the
 * installed builders — and handed to the compiler, so `SemanticValidator` asks what a
 * driver requires instead of naming one.
 */
final readonly class StorageRuleRegistry
{
    /**
     * @param array<string, StorageRules> $rules
     */
    public function __construct(private array $rules = [])
    {
    }

    public function forDriver(string $driver): ?StorageRules
    {
        return $this->rules[$driver] ?? null;
    }
}
