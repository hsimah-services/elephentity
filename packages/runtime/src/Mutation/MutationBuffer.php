<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\Identifier;

/**
 * Where a mutator's pending changes accumulate until the unit of work commits.
 *
 * A mutator is a command buffer, not a live row: setters record intent, and nothing
 * reaches storage until commit() runs verification over the complete pending state.
 * That is what allows every violation to be reported at once.
 */
interface MutationBuffer
{
    public function target(): Identifier;

    /**
     * Record a field's new value, in domain form. Processors run at commit.
     */
    public function set(string $field, mixed $value): void;

    public function isChanged(string $field): bool;

    public function edge(string $edge): EdgeMutation;

    /**
     * @return array<string, mixed>
     */
    public function changes(): array;
}
