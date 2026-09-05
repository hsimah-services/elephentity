<?php

declare(strict_types=1);

namespace PheFr\Runtime\Query;

use PheFr\Runtime\Identity\EntityId;

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
}
