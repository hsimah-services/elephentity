<?php

declare(strict_types=1);

namespace PheFr\WordPress\Migration;

/**
 * What the schema needs, split by what can be applied automatically.
 */
final readonly class MigrationPlan
{
    /**
     * @param list<string>  $statements Additive changes, safe to run unattended.
     * @param list<Refusal> $refusals   Changes that need a human to state their intent.
     */
    public function __construct(
        public array $statements = [],
        public array $refusals = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->statements && [] === $this->refusals;
    }

    public function isSafe(): bool
    {
        return [] === $this->refusals;
    }
}
