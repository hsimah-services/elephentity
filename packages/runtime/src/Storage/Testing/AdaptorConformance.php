<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage\Testing;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Criteria;
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
use RuntimeException;
use Throwable;

/**
 * The assertions any `StorageAdaptor` must satisfy, framework-agnostic so a
 * third-party adaptor can run it without taking on this repository's test framework.
 *
 * `StorageAdaptor` was narrow and defined before a second implementation existed
 * (#52 G5/G6) — this is what proves a second implementation actually honours the same
 * contract rather than merely type-checking against it. `check()` returns a list of
 * failures rather than asserting directly; a caller wraps it in whatever it already
 * uses (`self::assertSame([], $failures)` for PHPUnit, as this repo's own callers do).
 *
 * Every check uses entity and edge names the caller supplies, never a hardcoded one:
 * a schema-free adaptor like the memory one accepts anything, but a real backend may
 * need the name to already exist in whatever it was configured with.
 */
final readonly class AdaptorConformance
{
    /**
     * @param bool $sharedTargets Enable when this edge permits two owners to share a target.
     * @return list<string> Empty when the adaptor conforms.
     */
    public function check(StorageAdaptor $adaptor, string $entity, string $edge, string $related, bool $sharedTargets = false): array
    {
        $failures = [];

        $this->checkCrud($adaptor, $entity, $failures);
        $this->checkQuerying($adaptor, $entity, $failures);
        $this->checkPaging($adaptor, $entity, $failures);
        $this->checkEdges($adaptor, $entity, $edge, $related, $failures, $sharedTargets);
        $this->checkTransactions($adaptor, $entity, $failures);

        return $failures;
    }

    /**
     * @param list<string> $failures
     */
    private function checkCrud(StorageAdaptor $adaptor, string $entity, array &$failures): void
    {
        $result = $adaptor->write(new WriteBatch(new Insert($entity, $pending = new PendingId($entity), ['name' => 'first'])));

        if (!$result->wasAssigned($pending)) {
            $failures[] = 'write() did not assign an id to an Insert.';

            return;
        }

        $id = $result->idFor($pending);
        $record = $adaptor->get($entity, $id);

        if (null === $record || 'first' !== $record->value('name')) {
            $failures[] = 'get() did not read back the value just written.';
        }

        $bogus = EntityId::of($id->raw() . '-missing');

        if (null !== $adaptor->get($entity, $bogus)) {
            $failures[] = 'get() returned a record for an id nothing wrote.';
        }

        $many = $adaptor->getMany($entity, [$id, $bogus]);

        if (1 !== count($many) || $id->raw() !== $many[0]->id->raw()) {
            $failures[] = 'getMany() did not return exactly the row that exists.';
        }

        $adaptor->write(new WriteBatch(new Update($entity, $id, ['name' => 'updated'])));
        $updated = $adaptor->get($entity, $id);

        if (null === $updated || 'updated' !== $updated->value('name')) {
            $failures[] = 'A write()ten Update was not visible to a later get().';
        }

        $adaptor->write(new WriteBatch(new Delete($entity, $id)));

        if (null !== $adaptor->get($entity, $id)) {
            $failures[] = 'get() still finds a row after a write()ten Delete.';
        }
    }

    /**
     * @param list<string> $failures
     */
    private function checkQuerying(StorageAdaptor $adaptor, string $entity, array &$failures): void
    {
        $ids = $this->seed($adaptor, $entity, ['alpha' => 3, 'beta' => 1, 'gamma' => 2]);

        $matches = $adaptor->query(Criteria::for($entity)->where(Filter::equals('name', 'beta')))->items;

        if (1 !== count($matches) || 'beta' !== $matches[0]->value('name')) {
            $failures[] = 'query() with an equals filter did not narrow to the matching row.';
        }

        $ascending = $adaptor->query(Criteria::for($entity)->orderBy(Order::ascending('rank')))->items;
        $names = array_map(static fn ($record) => $record->value('name'), $ascending);

        if (['beta', 'gamma', 'alpha'] !== $names) {
            $failures[] = sprintf('query() with orderBy(ascending) returned %s, not the rank order.', json_encode($names));
        }

        $descending = $adaptor->query(Criteria::for($entity)->orderBy(Order::descending('rank')))->items;
        $reversedNames = array_map(static fn ($record) => $record->value('name'), $descending);

        if (['alpha', 'gamma', 'beta'] !== $reversedNames) {
            $failures[] = sprintf('query() with orderBy(descending) returned %s, not the reverse rank order.', json_encode($reversedNames));
        }

        $total = $adaptor->count(Criteria::for($entity)->unbounded());

        if ($total < 3) {
            $failures[] = sprintf('count() reported %d for a criteria matching at least 3 rows.', $total);
        }

        $filteredCount = $adaptor->count(Criteria::for($entity)->where(Filter::equals('name', 'beta')));

        if (1 !== $filteredCount) {
            $failures[] = sprintf('count() with a filter reported %d instead of 1.', $filteredCount);
        }

        $this->cleanUp($adaptor, $entity, $ids);
    }

    /**
     * @param list<string> $failures
     */
    private function checkPaging(StorageAdaptor $adaptor, string $entity, array &$failures): void
    {
        $ids = $this->seed($adaptor, $entity, ['one' => 1, 'two' => 2, 'three' => 3]);

        $criteria = Criteria::for($entity)->orderBy(Order::ascending('rank'))->take(2);
        $first = $adaptor->query($criteria);

        if (2 !== count($first->items) || !$first->hasMore()) {
            $failures[] = 'A first page of 2 over 3 rows did not come back full with more to fetch.';
        }

        if ($first->hasMore()) {
            $second = $adaptor->query($criteria->take(2, $first->next));

            if (1 !== count($second->items) || $second->hasMore()) {
                $failures[] = 'The second page did not carry the one remaining row with nothing further.';
            }
        }

        $zero = $adaptor->query(Criteria::for($entity)->take(0));
        if ([] !== $zero->items || $zero->hasMore()) {
            $failures[] = 'take(0) must return an empty terminal page.';
        }
        try {
            $adaptor->query(Criteria::for($entity)->take(-1));
            $failures[] = 'take(-1) must reject a negative page size.';
        } catch (Throwable) {
        }
        $this->cleanUp($adaptor, $entity, $ids);

        $ties = $this->seed($adaptor, $entity, array_fill_keys(array_map(static fn (int $n): string => 'tie-' . $n, range(1, 10)), 1));
        $expected = array_map(static fn (EntityId $id): int|string => $id->raw(), array_values($ties));
        sort($expected);
        foreach ([Criteria::for($entity), Criteria::for($entity)->orderBy(Order::ascending('rank'))] as $criteria) {
            $seen = [];
            $cursor = null;
            for ($step = 0; $step < 5; ++$step) {
                $page = $adaptor->query($criteria->take(3, $cursor));
                foreach ($page->items as $record) {
                    $seen[] = $record->id->raw();
                }
                $cursor = $page->next;
                if (null === $cursor) {
                    break;
                }
            }
            if ($seen !== $expected || null !== $cursor) {
                $failures[] = 'Paged tied and no-order rows must appear exactly once in ascending id order.';
            }
        }
        $this->cleanUp($adaptor, $entity, $ties);

        $many = [];
        for ($i = 0; $i <= Page::MAX_LIMIT; ++$i) {
            $many['bounded-' . $i] = $i;
        }
        $bounded = $this->seed($adaptor, $entity, $many);
        $first = $adaptor->query(Criteria::for($entity)->take(Page::MAX_LIMIT + 50));
        if (Page::MAX_LIMIT !== count($first->items) || !$first->hasMore() || Page::MAX_LIMIT !== Offset::fromCursor($first->next)->value) {
            $failures[] = 'Oversized pages must cap at Page::MAX_LIMIT with an effective-size cursor.';
        }
        if ($first->hasMore()) {
            $last = $adaptor->query(Criteria::for($entity)->take(Page::MAX_LIMIT + 50, $first->next));
            $walk = array_map(static fn (Record $record): int|string => $record->id->raw(), [...$first->items, ...$last->items]);
            $expected = array_map(static fn (EntityId $id): int|string => $id->raw(), array_values($bounded));
            sort($expected);
            if ($walk !== $expected || $last->hasMore()) {
                $failures[] = 'A capped page walk must resume after its effective size without gaps or duplicates.';
            }
        }
        $this->cleanUp($adaptor, $entity, $bounded);
    }

    /**
     * @param list<string> $failures
     */
    private function checkEdges(StorageAdaptor $adaptor, string $entity, string $edge, string $related, array &$failures, bool $sharedTargets): void
    {
        $ids = $this->seed($adaptor, $entity, ['owner' => 1, 'other-owner' => 2]);
        $ownerId = $ids['owner'];

        $relatedResult = $adaptor->write(new WriteBatch(new Insert($related, $relatedPending = new PendingId($related), ['name' => 'target'])));
        $relatedId = $relatedResult->idFor($relatedPending);

        $adaptor->write(new WriteBatch(new Link($entity, $edge, $ownerId, $relatedId)));

        $forward = $adaptor->query(Criteria::for($entity)->linkedTo(EdgeFilter::back($entity, $edge, $relatedId)))->items;

        if (1 !== count($forward) || $ownerId->raw() !== $forward[0]->id->raw()) {
            $failures[] = 'A Link was not visible to a query linkedTo(EdgeFilter::back()) on the declaring side.';
        }

        $reverse = $adaptor->query(Criteria::for($related)->linkedTo(EdgeFilter::along($entity, $edge, $ownerId)))->items;

        if (1 !== count($reverse) || $relatedId->raw() !== $reverse[0]->id->raw()) {
            $failures[] = 'A Link was not visible to a query linkedTo(EdgeFilter::along()) on the target side.';
        }

        $other = new PendingId($related);
        $otherId = $adaptor->write(new WriteBatch(new Insert($related, $other, ['name' => 'other-target'])))->idFor($other);
        $adaptor->write(new WriteBatch(new Unlink($entity, $edge, $ownerId, $otherId)));
        foreach ([Criteria::for($entity)->linkedTo(EdgeFilter::back($entity, $edge, $relatedId)), Criteria::for($related)->linkedTo(EdgeFilter::along($entity, $edge, $ownerId))] as $criteria) {
            if (1 !== count($adaptor->query($criteria)->items)) {
                $failures[] = 'Targeted unlink of a different target must retain the existing link.';
            }
        }

        $adaptor->write(new WriteBatch(new Unlink($entity, $edge, $ownerId, $relatedId)));

        $afterUnlink = $adaptor->query(Criteria::for($entity)->linkedTo(EdgeFilter::back($entity, $edge, $relatedId)))->items;

        if ([] !== $afterUnlink) {
            $failures[] = 'A row was still linked after an Unlink named it explicitly.';
        }

        $adaptor->write(new WriteBatch(new Link($entity, $edge, $ownerId, $relatedId), new Unlink($entity, $edge, $ownerId)));
        if ([] !== $adaptor->query(Criteria::for($related)->linkedTo(EdgeFilter::along($entity, $edge, $ownerId)))->items) {
            $failures[] = 'Clear-all unlink must remove every link from the owner.';
        }
        $otherOwner = $ids['other-owner'];
        $adaptor->write(new WriteBatch(new Link($entity, $edge, $ownerId, $relatedId), new Link($entity, $edge, $otherOwner, $otherId)));
        $expectedForward = [[(string) $relatedId, (string) $ownerId], [(string) $otherId, (string) $otherOwner]];
        $expectedReverse = [[(string) $ownerId, (string) $relatedId], [(string) $otherOwner, (string) $otherId]];
        if ($sharedTargets) {
            $adaptor->write(new WriteBatch(new Link($entity, $edge, $otherOwner, $relatedId)));
            $expectedForward[] = [(string) $relatedId, (string) $otherOwner];
            $expectedReverse[] = [(string) $otherOwner, (string) $relatedId];
        }
        foreach ([
            [Criteria::for($related)->linkedTo(EdgeFilter::along($entity, $edge, $ownerId, $otherOwner)), $expectedForward],
            [Criteria::for($entity)->linkedTo(EdgeFilter::back($entity, $edge, $relatedId, $otherId)), $expectedReverse],
        ] as [$criteria, $expected]) {
            $pairs = array_map(static fn (Record $row): array => [(string) $row->id, (string) $row->value(EdgeFilter::PARENT_COLUMN)], $adaptor->query($criteria)->items);
            sort($pairs);
            sort($expected);
            if ($pairs !== $expected) {
                $failures[] = 'Batched relationship queries must project exactly one (id, parent) pair per link in both directions.';
            }
        }
        $adaptor->write(new WriteBatch(new Unlink($entity, $edge, $ownerId), new Unlink($entity, $edge, $otherOwner)));
        $this->cleanUp($adaptor, $entity, $ids);
        $this->cleanUp($adaptor, $related, ['target' => $relatedId, 'other' => $otherId]);
    }

    /**
     * @param list<string> $failures
     */
    private function checkTransactions(StorageAdaptor $adaptor, string $entity, array &$failures): void
    {
        $value = $adaptor->transaction(static fn (): int => 42);

        if (42 !== $value) {
            $failures[] = 'transaction() did not return the work callback\'s own return value.';
        }

        $pending = new PendingId($entity);
        $threw = false;

        try {
            $adaptor->transaction(function () use ($adaptor, $entity, $pending): void {
                $adaptor->write(new WriteBatch(new Insert($entity, $pending, ['name' => 'doomed'])));

                throw new RuntimeException('conformance: deliberate failure');
            });
        } catch (RuntimeException $exception) {
            $threw = true;

            if ('conformance: deliberate failure' !== $exception->getMessage()) {
                throw $exception;
            }
        }

        // PHPStan infers the work callback's return type as never and, from that,
        // that a conforming transaction() can only ever reach here via the catch —
        // true of a conforming one, which is exactly why a non-conforming one needs
        // catching here rather than assumed away.
        if (!$threw) { // @phpstan-ignore booleanNot.alwaysFalse
            $failures[] = 'transaction() swallowed an exception thrown by its work callback.';
        }

        $survivors = $adaptor->query(Criteria::for($entity)->where(Filter::equals('name', 'doomed')))->items;

        if ([] !== $survivors) {
            $failures[] = 'A write inside a transaction that threw was not rolled back.';

            $this->cleanUp($adaptor, $entity, ['doomed' => $survivors[0]->id]);
        }
    }

    /**
     * @param array<string, int> $rows name => rank
     *
     * @return array<string, EntityId> name => assigned id
     */
    private function seed(StorageAdaptor $adaptor, string $entity, array $rows): array
    {
        $ids = [];

        foreach ($rows as $name => $rank) {
            $pending = new PendingId($entity);
            $result = $adaptor->write(new WriteBatch(new Insert($entity, $pending, ['name' => $name, 'rank' => $rank])));
            $ids[$name] = $result->idFor($pending);
        }

        return $ids;
    }

    /**
     * Conformance leaves nothing behind: each check seeds only what it needs and
     * removes it before returning, so checks that run in sequence against the same
     * adaptor never see one another's rows.
     *
     * @param array<string, Identifier> $ids
     */
    private function cleanUp(StorageAdaptor $adaptor, string $entity, array $ids): void
    {
        foreach ($ids as $id) {
            if ($id instanceof EntityId) {
                $adaptor->write(new WriteBatch(new Delete($entity, $id)));
            }
        }
    }
}
