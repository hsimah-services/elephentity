<?php

declare(strict_types=1);

namespace Eleph\Cli;

/**
 * One target's block from eleph.json.
 *
 * `output` is read here because the core has to know where to write and what to diff.
 * Everything else stays in `settings`, uninspected: what `typeNamespace` means is a
 * property of the PHP target, and a core that learned it would have moved the coupling
 * rather than removed it. See docs/PLAN.md §15.
 */
final readonly class TargetConfig
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public string $name,
        public string $outputDirectory,
        public array $settings,
    ) {
    }
}
