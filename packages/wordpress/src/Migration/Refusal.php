<?php

declare(strict_types=1);

namespace PheFr\WordPress\Migration;

/**
 * A change the planner will not make on its own.
 *
 * A diff of two schema versions cannot infer intent: `title` disappearing while
 * `heading` appears is either a rename or a drop-plus-add, and guessing wrong destroys
 * data. So the planner stops and says what it saw, and a human writes the migration.
 */
final readonly class Refusal
{
    public function __construct(
        public string $code,
        public string $message,
        public string $table,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s: %s [%s]', $this->table, $this->message, $this->code);
    }
}
