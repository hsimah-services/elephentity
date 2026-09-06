<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

use Eleph\Schema\Ir\RelationKind;

/**
 * Where one edge physically lives.
 *
 * Derived once and shared: the schema builder uses it to place columns and tables, and
 * the query compiler uses it to answer "linked to". Two derivations would be two
 * chances to disagree about the same edge.
 */
final readonly class EdgePlacement
{
    public function __construct(
        public string $entity,
        public string $edge,
        public string $target,
        public RelationKind $relation,
        /** The table carrying the link: the far side, this side, or a join table. */
        public string $table,
        /** The column pointing back at the declaring entity. */
        public string $localColumn,
        /** Join tables only: the column pointing at the target. */
        public ?string $targetColumn = null,
        /** The table rows of the target entity live in. */
        public string $targetTable = '',
    ) {
    }

    public function usesJoinTable(): bool
    {
        return null !== $this->targetColumn;
    }

    /**
     * Whether the link column sits on the declaring entity's own table.
     *
     * When it does, finding what a Post links to means reading Post's own row rather
     * than filtering the target — a different query shape entirely.
     */
    public function keyIsLocal(): bool
    {
        return !$this->usesJoinTable() && $this->relation->keyIsLocal();
    }
}
