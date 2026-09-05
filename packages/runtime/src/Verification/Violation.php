<?php

declare(strict_types=1);

namespace PheFr\Runtime\Verification;

/**
 * A single reason a value was rejected.
 *
 * A violation deliberately carries no field name. Type processors are shared across
 * entities and do not know which field they are attached to, so the Mutator attaches
 * the field path as it aggregates violations from every verifier.
 */
final readonly class Violation
{
    public function __construct(
        /** Stable, machine-readable identifier, e.g. "money.negative". */
        public string $code,
        /** Human-readable explanation. Never includes the field name. */
        public string $message,
    ) {
    }
}
