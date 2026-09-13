<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;

/**
 * A criteria that has not run yet.
 *
 * Nothing reaches the database until one of these methods is called, which is what
 * makes it safe for an entity to hand one back from an edge accessor: asking a Post
 * for its comments costs nothing until you ask the comments something.
 *
 * count() goes to the adaptor's count rather than hydrating and counting, so asking
 * how many comments a post has never builds a single Comment.
 *
 * @template T of object
 *
 * @implements EntityQuery<T>
 */
final readonly class LazyEntityQuery implements EntityQuery
{
    /**
     * @param Hydrator<T> $hydrator
     */
    public function __construct(
        private StorageAdaptor $storage,
        private Hydrator $hydrator,
        private EdgeLoader $edges,
        private Criteria $criteria,
        private ReadGate $gate,
    ) {
    }

    public function count(): int
    {
        // A database count includes rows the viewer cannot receive, so gated entities
        // must hydrate the unbounded set and count the survivors.
        if ($this->gate->guards($this->criteria->entity)) {
            return count($this->hydrateAll($this->storage->query($this->criteria->unbounded())->items));
        }

        return $this->storage->count($this->criteria->unbounded());
    }

    public function page(int $limit, ?Cursor $after = null): Page
    {
        $page = $this->storage->query($this->criteria->take($limit, $after));

        return new Page($this->hydrateAll($page->items), $page->next);
    }

    public function all(): array
    {
        // Deliberately unbounded, and deliberately explicit: reaching for all() is how
        // you say the set is known to be small.
        return $this->hydrateAll($this->storage->query($this->criteria->unbounded())->items);
    }

    public function first(): ?object
    {
        $items = $this->page(1)->items;

        return $items[0] ?? null;
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * @param list<Record> $records
     *
     * @return list<T>
     */
    private function hydrateAll(array $records): array
    {
        $objects = array_map(
            fn (Record $record): object => $this->hydrator->hydrate($record, $this->edges),
            $records,
        );

        /** @var list<T> $objects */
        $objects = array_values($objects);

        /** @var list<T> $retained */
        $retained = $this->gate->retain($this->criteria->entity, $objects);

        return $retained;
    }
}
