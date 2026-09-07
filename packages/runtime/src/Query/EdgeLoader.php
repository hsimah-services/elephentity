<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Identity\EntityId;

/**
 * Resolves an entity's edges, lazily and in batches.
 *
 * Generated entities hold one of these rather than their related entities, so nothing
 * is fetched until an accessor is called — and when fifty posts each ask for their
 * comments, the loader issues one query rather than fifty.
 */
interface EdgeLoader
{
    /**
     * @return EntityQuery<object>
     */
    public function toMany(string $entity, EntityId $id, string $edge): EntityQuery;

    public function toOne(string $entity, EntityId $id, string $edge): ?object;

    /**
     * The same edge, read from the far end: rows of `$entity` whose `$edge` points at
     * `$id`.
     *
     * An inverse is not a second edge, so it is addressed by the edge that declares
     * it. That is what lets `Item` answer "what is stocked here?" without `Item.yml`
     * gaining an edge of its own — and what stops the two descriptions of one
     * relationship ever disagreeing.
     *
     * @return EntityQuery<object>
     */
    public function inverseToMany(string $entity, string $edge, EntityId $id): EntityQuery;

    public function inverseToOne(string $entity, string $edge, EntityId $id): ?object;
}
