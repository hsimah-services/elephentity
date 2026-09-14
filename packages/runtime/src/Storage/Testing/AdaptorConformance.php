<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage\Testing;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Order;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use RuntimeException;

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
     * @return list<string> Empty when the adaptor conforms.
     */
    public function check(StorageAdaptor $adaptor, string $entity, string $edge, string $related): array
    {
        $failures = [];

        $this->checkCrud($adaptor, $entity, $failures);
        $this->checkQuerying($adaptor, $entity, $failures);
        $this->checkPaging($adaptor, $entity, $failures);
        $this->checkEdges($adaptor, $entity, $edge, $related, $failures);
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

        $this->cleanUp($adaptor, $entity, $ids);
    }

    /**
     * @param list<string> $failures
     */
    private function checkEdges(StorageAdaptor $adaptor, string $entity, string $edge, string $related, array &$failures): void
    {
        $ids = $this->seed($adaptor, $entity, ['owner' => 1]);
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

        $adaptor->write(new WriteBatch(new Unlink($entity, $edge, $ownerId, $relatedId)));

        $afterUnlink = $adaptor->query(Criteria::for($entity)->linkedTo(EdgeFilter::back($entity, $edge, $relatedId)))->items;

        if ([] !== $afterUnlink) {
            $failures[] = 'A row was still linked after an Unlink named it explicitly.';
        }

        $this->cleanUp($adaptor, $entity, $ids);
        $this->cleanUp($adaptor, $related, ['target' => $relatedId]);
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
