<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Query;

use Eleph\Runtime\Catalogue\EntityCatalogue;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Policy\AnonymousViewerProvider;
use Eleph\Runtime\Policy\PolicyDecision;
use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Query\CachingEdgeLoader;
use Eleph\Runtime\Query\EdgeLoader;
use Eleph\Runtime\Query\Hydrator;
use Eleph\Runtime\Query\HydratorRegistry;
use Eleph\Runtime\Query\LazyEntityQuery;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Tests\Policy\StubPolicies;
use PHPUnit\Framework\TestCase;

final class PolicyQueryTest extends TestCase
{
    public function testLazyQueryFiltersAndCountsSurvivors(): void
    {
        $records = [
            new Record('Post', EntityId::of(1), ['allowed' => 1]),
            new Record('Post', EntityId::of(2), ['allowed' => 0]),
        ];
        $storage = $this->createMock(StorageAdaptor::class);
        $storage->method('query')->willReturn(new Page($records));
        $storage->expects(self::never())->method('count');
        $catalogue = $this->createStub(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturn(new StubPolicies(
            static fn (object $entity): PolicyDecision => $entity instanceof PolicyEntity && $entity->allowed
                ? PolicyDecision::allow()
                : PolicyDecision::deny('hidden'),
        ));
        $gate = new ReadGate($catalogue, new AnonymousViewerProvider());
        $hydrator = $this->hydrator();
        $query = new LazyEntityQuery($storage, $hydrator, $this->createStub(EdgeLoader::class), Criteria::for('Post'), $gate);

        self::assertCount(1, $query->all());
        self::assertSame(1, $query->count());
    }

    public function testAGatedPageCountsOnlyVisibleRows(): void
    {
        // Rows 1, 3 and 6 are hidden. Pages come back full, never repeat a row, and the
        // cursor counts what the viewer saw, so it says nothing about the hidden rows.
        $query = $this->pagedQuery([1 => 0, 2 => 1, 3 => 0, 4 => 1, 5 => 1, 6 => 0]);

        $first = $query->page(2);
        self::assertSame([2, 4], $this->ids($first->items));
        self::assertSame(2, Offset::fromCursor($first->next)->value);

        $second = $query->page(2, $first->next);
        self::assertSame([5], $this->ids($second->items));
        self::assertNull($second->next, 'only hidden rows remain, so there is no next page');
    }

    public function testADeepGatedPageStartsAfterTheVisibleRowsBeforeIt(): void
    {
        // More rows than one storage chunk, every other one hidden.
        $allowed = [];
        for ($id = 1; $id <= 250; ++$id) {
            $allowed[$id] = $id % 2;
        }
        $query = $this->pagedQuery($allowed);

        $page = $query->page(3, (new Offset(100))->toCursor());

        self::assertSame([201, 203, 205], $this->ids($page->items));
        self::assertSame(103, Offset::fromCursor($page->next)->value);
        self::assertSame(125, $query->count());
    }

    public function testFirstFindsAReadableRowBehindADeniedOne(): void
    {
        $first = $this->pagedQuery([1 => 0, 2 => 1])->first();

        self::assertInstanceOf(PolicyEntity::class, $first);
        self::assertSame(2, $first->id);
    }

    public function testCachingPreloadFiltersEachParentGroup(): void
    {
        $records = [
            new Record('Comment', EntityId::of(1), ['__parent' => 1, 'allowed' => 1]),
            new Record('Comment', EntityId::of(2), ['__parent' => 1, 'allowed' => 0]),
        ];
        $storage = $this->createStub(StorageAdaptor::class);
        $storage->method('query')->willReturn(new Page($records));
        $catalogue = $this->createStub(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturn(new StubPolicies(
            static fn (object $entity): PolicyDecision => $entity instanceof PolicyEntity && $entity->allowed
                ? PolicyDecision::allow()
                : PolicyDecision::deny('hidden'),
        ));
        $gate = new ReadGate($catalogue, new AnonymousViewerProvider());
        $hydrator = $this->hydrator();
        $registry = $this->createStub(HydratorRegistry::class);
        $registry->method('get')->willReturn($hydrator);
        $loader = new CachingEdgeLoader($storage, $registry, ['Post.comments' => 'Comment'], $gate);

        $result = $loader->preload('Post', [EntityId::of(1), EntityId::of(2)], 'comments');

        self::assertArrayHasKey('1', $result);
        self::assertCount(1, $result['1'] ?? []);
    }

    /**
     * A query over rows that pages the way an adaptor does: an offset cursor, and a next
     * cursor only while rows remain.
     *
     * @param array<int, int> $allowed id => 1 when readable
     *
     * @return LazyEntityQuery<object>
     */
    private function pagedQuery(array $allowed): LazyEntityQuery
    {
        $records = [];
        foreach ($allowed as $id => $flag) {
            $records[] = new Record('Post', EntityId::of($id), ['allowed' => $flag]);
        }
        $storage = $this->createStub(StorageAdaptor::class);
        $storage->method('query')->willReturnCallback(static function (Criteria $criteria) use ($records): Page {
            $offset = Offset::fromCursor($criteria->after)->value;
            $limit = (int) $criteria->limit;
            $more = count($records) > $offset + $limit;

            return new Page(array_slice($records, $offset, $limit), $more ? (new Offset($offset + $limit))->toCursor() : null);
        });
        $catalogue = $this->createStub(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturn(new StubPolicies(
            static fn (object $entity): PolicyDecision => $entity instanceof PolicyEntity && $entity->allowed
                ? PolicyDecision::allow()
                : PolicyDecision::deny('hidden'),
        ));

        return new LazyEntityQuery(
            $storage,
            $this->hydrator(),
            $this->createStub(EdgeLoader::class),
            Criteria::for('Post'),
            new ReadGate($catalogue, new AnonymousViewerProvider()),
        );
    }

    /**
     * @param list<object> $items
     *
     * @return list<int>
     */
    private function ids(array $items): array
    {
        return array_map(static fn (object $e): int => $e instanceof PolicyEntity ? $e->id : 0, $items);
    }

    /** @return Hydrator<object> */
    private function hydrator(): Hydrator
    {
        return new class () implements Hydrator {
            public function hydrate(Record $record, EdgeLoader $edges): object
            {
                return new PolicyEntity((bool) $record->value('allowed'), (int) $record->id->raw());
            }
        };
    }
}

final class PolicyEntity
{
    public function __construct(public bool $allowed, public int $id = 0)
    {
    }
}
