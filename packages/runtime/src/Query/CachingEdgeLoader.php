<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\StorageAdaptor;
use RuntimeException;

/**
 * Resolves edges, and remembers what it has already resolved.
 *
 * Two distinct savings, worth separating because they are often confused.
 *
 * **Laziness** is free: an edge accessor returns a query, so traversing a graph costs
 * nothing until something is asked of it.
 *
 * **Batching** is not free, and cannot be retrofitted onto a call that has already
 * happened. Once fifty posts have each been asked for their comments one at a time,
 * fifty queries have run. Genuine batching needs a caller that knows all fifty ids up
 * front — a GraphQL resolver does, which is why preload() exists and is called there
 * rather than guessed at here.
 *
 * Between the two sits the identity map: asking the same post for its comments twice
 * costs one query, not two.
 */
final class CachingEdgeLoader implements EdgeLoader
{
    /** @var array<string, object|null> */
    private array $toOne = [];

    public function __construct(
        private readonly StorageAdaptor $storage,
        private readonly HydratorRegistry $hydrators,
        /** @var array<string, string> "Entity.edge" => target entity name. */
        private readonly array $targets,
    ) {
    }

    public function toMany(string $entity, EntityId $id, string $edge): EntityQuery
    {
        $target = $this->target($entity, $edge);

        return $this->query($target, EdgeFilter::along($entity, $edge, $id));
    }

    public function toOne(string $entity, EntityId $id, string $edge): ?object
    {
        return $this->one(
            sprintf('%s#%s.%s', $entity, $id, $edge),
            $this->target($entity, $edge),
            EdgeFilter::along($entity, $edge, $id),
        );
    }

    public function inverseToMany(string $entity, string $edge, EntityId $id): EntityQuery
    {
        // Read backwards, the rows are of the entity that declares the edge — so it is
        // both the thing being filtered and the thing being hydrated.
        return $this->query($entity, EdgeFilter::back($entity, $edge, $id));
    }

    public function inverseToOne(string $entity, string $edge, EntityId $id): ?object
    {
        return $this->one(
            sprintf('%s#%s.%s^', $entity, $id, $edge),
            $entity,
            EdgeFilter::back($entity, $edge, $id),
        );
    }

    /**
     * @return EntityQuery<object>
     */
    private function query(string $target, EdgeFilter $link): EntityQuery
    {
        return new LazyEntityQuery(
            $this->storage,
            $this->hydrators->get($target),
            $this,
            (new Criteria($target))->linkedTo($link),
        );
    }

    private function one(string $key, string $target, EdgeFilter $link): ?object
    {
        if (array_key_exists($key, $this->toOne)) {
            return $this->toOne[$key];
        }

        return $this->toOne[$key] = $this->query($target, $link)->first();
    }

    /**
     * Resolve one edge for many parents in a single query.
     *
     * The batching entry point. A caller that already holds every parent id — a
     * GraphQL connection resolver, a report — calls this first, and the individual
     * accessors then read from the identity map instead of querying.
     *
     * @param list<EntityId> $ids
     *
     * @return array<string, list<object>> Keyed by parent id.
     */
    public function preload(string $entity, array $ids, string $edge): array
    {
        if ([] === $ids) {
            return [];
        }

        $target = $this->target($entity, $edge);
        $hydrator = $this->hydrators->get($target);

        // One filter naming every parent, so this is one query rather than one each.
        $criteria = (new Criteria($target))->linkedTo(EdgeFilter::along($entity, $edge, ...$ids));

        $grouped = [];

        foreach ($ids as $id) {
            $grouped[(string) $id] = [];
        }

        foreach ($this->storage->query($criteria)->items as $record) {
            $parent = $record->value(EdgeFilter::PARENT_COLUMN);

            if (null === $parent) {
                continue;
            }

            $grouped[(string) $parent][] = $hydrator->hydrate($record, $this);
        }

        return $grouped;
    }

    private function target(string $entity, string $edge): string
    {
        return $this->targets[$entity . '.' . $edge]
            ?? throw new RuntimeException(sprintf('Edge %s.%s is not mapped.', $entity, $edge));
    }
}
