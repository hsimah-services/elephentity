<?php

declare(strict_types=1);

namespace Eleph\Memory;

use Eleph\Runtime\Capability\Capabilities;
use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Direction;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Order;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Storage\Write\WriteOperation;
use Eleph\Runtime\Storage\Write\WriteResult;
use RuntimeException;
use Throwable;

/**
 * Raw PHP arrays over the storage port, with no backend of its own.
 *
 * The neutral driver `driver: wordpress` never was: turning WordPress off used to
 * leave generated entities and mutators with nowhere to write (#52 G5). This is the
 * cheapest honest second implementation of `StorageAdaptor` — proving the port is a
 * real port, not one adaptor's shape asserted to be general — and it declares nothing
 * but Transactions, which is the honest answer for a store with no disk, no network
 * and no other process to disagree with it.
 *
 * Edges are the one thing no schema tells this adaptor about, because it has no
 * schema: a Link is stored as a fact — "$entity's $edge points at $to" — keyed by
 * entity and edge name alone, and a query asks the same fact back rather than a join
 * plan a manifest would otherwise have to supply.
 */
final class MemoryAdaptor implements StorageAdaptor
{
    /** @var array<string, array<string, Record>> entity => id (string) => Record */
    private array $records = [];

    /** @var array<string, array<string, list<string>>> "entity.edge" => from id (string) => list<to id (string)> */
    private array $relations = [];

    private int $nextId = 1;

    public function capabilities(): Capabilities
    {
        return new Capabilities('memory', Capability::Transactions);
    }

    public function get(string $entity, EntityId $id): ?Record
    {
        return $this->records[$entity][(string) $id] ?? null;
    }

    public function getMany(string $entity, array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            $record = $this->records[$entity][(string) $id] ?? null;

            if (null !== $record) {
                $found[] = $record;
            }
        }

        return $found;
    }

    public function query(Criteria $criteria): Page
    {
        $matches = $this->ordered($this->matching($criteria), $criteria->order);

        if (null === $criteria->limit) {
            return new Page($matches);
        }

        $offset = Offset::fromCursor($criteria->after)->value;

        // One row more than asked for: its presence is the answer to "is there
        // another page", dropped before anything above ever sees it.
        $window = array_slice($matches, $offset, $criteria->limit + 1);
        $hasMore = count($window) > $criteria->limit;

        return new Page(
            array_slice($window, 0, $criteria->limit),
            $hasMore ? (new Offset($offset + $criteria->limit))->toCursor() : null,
        );
    }

    public function count(Criteria $criteria): int
    {
        return count($this->matching($criteria));
    }

    public function write(WriteBatch $batch): WriteResult
    {
        $result = new WriteResult();

        foreach ($batch->operations as $operation) {
            $this->apply($operation, $result);
        }

        return $result;
    }

    public function transaction(callable $work): mixed
    {
        // No real transaction to open: a snapshot taken before $work runs and restored
        // if it throws is what makes "all of these writes or none of them" true for a
        // store that has no log of its own to roll back.
        $snapshot = [$this->records, $this->relations, $this->nextId];

        try {
            return $work();
        } catch (Throwable $exception) {
            [$this->records, $this->relations, $this->nextId] = $snapshot;

            throw $exception;
        }
    }

    private function apply(WriteOperation $operation, WriteResult $result): void
    {
        match (true) {
            $operation instanceof Insert => $this->insert($operation, $result),
            $operation instanceof Update => $this->update($operation),
            $operation instanceof Delete => $this->delete($operation),
            $operation instanceof Link => $this->link($operation),
            $operation instanceof Unlink => $this->unlink($operation),
            default => throw new RuntimeException(sprintf('Unknown write operation %s.', $operation::class)),
        };
    }

    private function insert(Insert $operation, WriteResult $result): void
    {
        $id = EntityId::of($this->nextId++);
        $entity = $operation->entity();

        $this->records[$entity][(string) $id] = new Record($entity, $id, $operation->values);
        $result->assign($operation->pendingId(), $id);
    }

    private function update(Update $operation): void
    {
        $entity = $operation->entity();
        $key = (string) $operation->target();
        $existing = $this->records[$entity][$key] ?? null;

        if (null === $existing) {
            throw new RuntimeException(sprintf('Cannot update %s#%s: no such row.', $entity, $key));
        }

        $this->records[$entity][$key] = new Record($entity, $existing->id, [...$existing->values, ...$operation->values]);
    }

    private function delete(Delete $operation): void
    {
        unset($this->records[$operation->entity()][(string) $operation->target()]);
    }

    private function link(Link $operation): void
    {
        $key = $operation->entity() . '.' . $operation->edge;
        $from = (string) $operation->from;
        $to = (string) $operation->to;

        $this->relations[$key][$from] ??= [];

        if (!in_array($to, $this->relations[$key][$from], true)) {
            $this->relations[$key][$from][] = $to;
        }
    }

    private function unlink(Unlink $operation): void
    {
        $key = $operation->entity() . '.' . $operation->edge;
        $from = (string) $operation->from;

        if (null === $operation->to) {
            unset($this->relations[$key][$from]);

            return;
        }

        $to = (string) $operation->to;
        $this->relations[$key][$from] = array_values(array_diff($this->relations[$key][$from] ?? [], [$to]));
    }

    /**
     * @return list<Record>
     */
    private function matching(Criteria $criteria): array
    {
        $records = array_values($this->records[$criteria->entity] ?? []);

        foreach ($criteria->filters as $filter) {
            $records = array_values(array_filter(
                $records,
                fn (Record $record): bool => $this->matchesFilter($record, $filter),
            ));
        }

        foreach ($criteria->links as $link) {
            $records = $this->matchingLink($records, $link);
        }

        return $records;
    }

    private function matchesFilter(Record $record, Filter $filter): bool
    {
        $value = $record->value($filter->field);

        return match ($filter->comparison) {
            Comparison::Equals => $value === $filter->value,
            Comparison::NotEquals => $value !== $filter->value,
            Comparison::LessThan => null !== $value && $value < $filter->value,
            Comparison::LessThanOrEqual => null !== $value && $value <= $filter->value,
            Comparison::GreaterThan => null !== $value && $value > $filter->value,
            Comparison::GreaterThanOrEqual => null !== $value && $value >= $filter->value,
            Comparison::In => is_array($filter->value) && in_array($value, $filter->value, true),
            Comparison::NotIn => !is_array($filter->value) || !in_array($value, $filter->value, true),
            Comparison::Contains => is_string($value) && is_string($filter->value) && str_contains($value, $filter->value),
            Comparison::StartsWith => is_string($value) && is_string($filter->value) && str_starts_with($value, $filter->value),
            Comparison::IsNull => null === $value,
            Comparison::IsNotNull => null !== $value,
        };
    }

    /**
     * @param list<Record> $records
     *
     * @return list<Record>
     */
    private function matchingLink(array $records, EdgeFilter $link): array
    {
        $key = $link->entity . '.' . $link->edge;
        $adjacency = $this->relations[$key] ?? [];
        $from = array_map(static fn ($id): string => (string) $id, $link->from);

        if ($link->reversed) {
            // The declaring entity's own rows, kept where the row's id maps (as
            // "from") to at least one of the given (target) ids.
            return array_values(array_filter(
                $records,
                static function (Record $record) use ($adjacency, $from): bool {
                    $linked = $adjacency[(string) $record->id] ?? [];

                    return [] !== array_intersect($linked, $from);
                },
            ));
        }

        // The far side: $from are declaring-entity row ids, so collect what they
        // point at and keep the rows that are among it.
        $reachable = [];

        foreach ($from as $id) {
            foreach ($adjacency[$id] ?? [] as $to) {
                $reachable[$to] = true;
            }
        }

        return array_values(array_filter(
            $records,
            static fn (Record $record): bool => isset($reachable[(string) $record->id]),
        ));
    }

    /**
     * @param list<Record> $records
     * @param list<Order>  $order
     *
     * @return list<Record>
     */
    private function ordered(array $records, array $order): array
    {
        if ([] === $order) {
            return $records;
        }

        usort($records, static function (Record $a, Record $b) use ($order): int {
            foreach ($order as $clause) {
                $left = $a->value($clause->field);
                $right = $b->value($clause->field);
                $comparison = $left <=> $right;

                if (0 !== $comparison) {
                    return Direction::Descending === $clause->direction ? -$comparison : $comparison;
                }
            }

            return 0;
        });

        return $records;
    }
}
