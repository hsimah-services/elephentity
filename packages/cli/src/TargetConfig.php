<?php

declare(strict_types=1);

namespace Eleph\Cli;

/**
 * One target's block from eleph.json.
 *
 * `output` and `builder` are read here because the core has to know where to write, what
 * to diff, and which program to run. Everything else stays in `settings`, uninspected:
 * what `typeNamespace` means is a property of the PHP target, and a core that learned it
 * would have moved the coupling rather than removed it. See docs/PLAN.md §15.
 *
 * **Every target names a builder.** Elephentity generates nothing itself — there is no
 * built-in target to fall back to — so a target without one is a target nothing can
 * produce, and saying so when the config loads beats discovering it mid-build.
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
        public string $builder,
    ) {
    }
}
