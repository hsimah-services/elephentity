<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;
use Generator;

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
    /** Rows read per storage query while walking a gated set. Under every adaptor's page cap. */
    private const CHUNK = 100;

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
        // must hydrate the set and count the survivors.
        if ($this->gate->guards($this->criteria->entity)) {
            return iterator_count($this->visible());
        }

        return $this->storage->count($this->criteria->unbounded());
    }

    public function page(int $limit, ?Cursor $after = null): Page
    {
        if (!$this->gate->guards($this->criteria->entity)) {
            $page = $this->storage->query($this->criteria->take($limit, $after));

            return new Page($this->hydrateAll($page->items), $page->next);
        }

        // A gated cursor counts only rows the viewer can see, so a hidden row leaves no
        // trace in it, and hasMore means another visible row exists.
        // shortcut: each gated page re-reads every row before it; keyset paging (#102) resumes in place.
        $offset = Offset::fromCursor($after)->value;
        $skip = $offset;
        $items = [];

        foreach ($this->visible() as $object) {
            if ($skip-- > 0) {
                continue;
            }

            if ($limit === count($items)) {
                return new Page($items, (new Offset($offset + $limit))->toCursor());
            }

            $items[] = $object;
        }

        return new Page($items);
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
     * Every row of the criteria the viewer may see, read a chunk at a time so memory
     * holds one chunk rather than the set.
     *
     * @return Generator<int, T>
     */
    private function visible(): Generator
    {
        $after = null;

        do {
            $page = $this->storage->query($this->criteria->take(self::CHUNK, $after));

            foreach ($this->hydrateAll($page->items) as $object) {
                yield $object;
            }

            $after = $page->next;
        } while (null !== $after);
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
