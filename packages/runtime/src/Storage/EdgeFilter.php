<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

use Eleph\Runtime\Identity\Identifier;
use InvalidArgumentException;

/**
 * Constrain a result set to what one or more entities are linked to along an edge.
 *
 * Edges cannot be expressed as field filters: the foreign key column belongs to no
 * declared field, and a many-to-many link lives in a table the spec never names. So
 * the port says "linked to", and the adaptor — which knows whether that is a column or
 * a join — decides how to ask.
 *
 * Several parents in one filter mean *any of them*, which is how one query serves
 * fifty posts asking for their comments. Separate filters on a criteria remain
 * conjunctive, as field filters are.
 */
final readonly class EdgeFilter
{
    /**
     * The column an adaptor projects to say which parent a row belongs to.
     *
     * Only present when the filter names more than one parent, since that is the only
     * case where the caller cannot already tell.
     */
    public const PARENT_COLUMN = '__parent';

    /** @var list<Identifier> */
    public array $from;

    public function __construct(
        /** The entity that declares the edge. */
        public string $entity,
        public string $edge,
        Identifier ...$from,
    ) {
        if ([] === $from) {
            throw new InvalidArgumentException('An edge filter needs at least one parent.');
        }

        $this->from = array_values($from);
    }

    /**
     * Whether the adaptor must project PARENT_COLUMN so results can be grouped.
     */
    public function needsParentColumn(): bool
    {
        return count($this->from) > 1;
    }
}
