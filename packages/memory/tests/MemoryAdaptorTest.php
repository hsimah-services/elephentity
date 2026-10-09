<?php

declare(strict_types=1);

namespace Eleph\Memory\Tests;

use Eleph\Memory\MemoryAdaptor;
use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Testing\AdaptorConformance;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\WriteBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The cheapest honest second implementation of `StorageAdaptor` (#52 M5): the
 * conformance suite in `elephentity/runtime` proves it round-trips, orders, pages,
 * links and rolls back exactly as the port promises, for real — no fake standing in
 * for a backend, because this adaptor has no backend other than the PHP array it is.
 */
#[CoversClass(MemoryAdaptor::class)]
final class MemoryAdaptorTest extends TestCase
{
    public function testItConformsToTheStoragePort(): void
    {
        $failures = (new AdaptorConformance())->check(new MemoryAdaptor(), 'ConformanceEntity', 'related', 'ConformanceRelated');

        self::assertSame([], $failures, implode("\n", $failures));
    }

    public function testItDeclaresOnlyTransactions(): void
    {
        $capabilities = (new MemoryAdaptor())->capabilities();

        self::assertTrue($capabilities->supports(Capability::Transactions));
        self::assertSame(['transactions'], array_map(
            static fn (Capability $capability): string => $capability->value,
            $capabilities->all(),
        ));
    }

    public function testTwoEntitiesWithTheSameIdDoNotCollide(): void
    {
        // Ids are assigned by one shared counter, but records are keyed by entity too:
        // Post#1 and Comment#1 are different rows.
        $adaptor = new MemoryAdaptor();

        $failures = (new AdaptorConformance())->check($adaptor, 'Alpha', 'related', 'Beta');
        self::assertSame([], $failures);

        $failures = (new AdaptorConformance())->check($adaptor, 'Beta', 'related', 'Alpha');
        self::assertSame([], $failures);
    }

    public function testABatchedEdgeQuerySaysWhichParentEachRowBelongsTo(): void
    {
        // A batch loader groups by the parent column; without it every group came back empty.
        $adaptor = new MemoryAdaptor();
        [$p1, $p2] = [$this->insert($adaptor, 'Post'), $this->insert($adaptor, 'Post')];
        [$shared, $own] = [$this->insert($adaptor, 'Comment'), $this->insert($adaptor, 'Comment')];
        $adaptor->write(new WriteBatch(
            new Link('Post', 'comments', $p1, $shared),
            new Link('Post', 'comments', $p2, $shared),
            new Link('Post', 'comments', $p2, $own),
        ));

        $forward = $adaptor->query(Criteria::for('Comment')->linkedTo(EdgeFilter::along('Post', 'comments', $p1, $p2)));
        self::assertEqualsCanonicalizing(
            ["$shared<-$p1", "$shared<-$p2", "$own<-$p2"],
            $this->pairs($forward->items),
        );

        $back = $adaptor->query(Criteria::for('Post')->linkedTo(EdgeFilter::back('Post', 'comments', $shared, $own)));
        self::assertEqualsCanonicalizing(
            ["$p1<-$shared", "$p2<-$shared", "$p2<-$own"],
            $this->pairs($back->items),
        );

        $single = $adaptor->query(Criteria::for('Comment')->linkedTo(EdgeFilter::along('Post', 'comments', $p2)));
        self::assertCount(2, $single->items);
        self::assertFalse($single->items[0]->has(EdgeFilter::PARENT_COLUMN), 'one parent needs no grouping');
    }

    private function insert(MemoryAdaptor $adaptor, string $entity): EntityId
    {
        $pending = new PendingId($entity);

        return $adaptor->write(new WriteBatch(new Insert($entity, $pending, [])))->idFor($pending);
    }

    /**
     * @param list<Record> $records
     *
     * @return list<string>
     */
    private function pairs(array $records): array
    {
        return array_map(
            static fn (Record $record): string => $record->id . '<-' . $record->value(EdgeFilter::PARENT_COLUMN),
            $records,
        );
    }
}
