<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\Identifier;

/**
 * Pending changes to one edge.
 *
 * Takes identifiers rather than entities so a unit of work can link rows that do not
 * exist yet — the Comment being attached to a Post in the same commit.
 */
interface EdgeMutation
{
    public function add(Identifier $target): void;

    public function remove(Identifier $target): void;

    /**
     * Replace the edge's contents wholesale.
     *
     * @param list<Identifier> $targets
     */
    public function set(array $targets): void;
}
