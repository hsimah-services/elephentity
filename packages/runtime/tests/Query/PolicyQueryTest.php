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
        $catalogue = $this->createMock(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturn(new StubPolicies(
            static fn (object $entity): PolicyDecision => $entity instanceof PolicyEntity && $entity->allowed
                ? PolicyDecision::allow()
                : PolicyDecision::deny('hidden'),
        ));
        $gate = new ReadGate($catalogue, new AnonymousViewerProvider());
        $hydrator = $this->hydrator();
        $query = new LazyEntityQuery($storage, $hydrator, $this->createMock(EdgeLoader::class), new Criteria('Post'), $gate);

        self::assertCount(1, $query->all());
        self::assertSame(1, $query->count());
    }

    public function testCachingPreloadFiltersEachParentGroup(): void
    {
        $records = [
            new Record('Comment', EntityId::of(1), ['__parent' => 1, 'allowed' => 1]),
            new Record('Comment', EntityId::of(2), ['__parent' => 1, 'allowed' => 0]),
        ];
        $storage = $this->createMock(StorageAdaptor::class);
        $storage->method('query')->willReturn(new Page($records));
        $catalogue = $this->createMock(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturn(new StubPolicies(
            static fn (object $entity): PolicyDecision => $entity instanceof PolicyEntity && $entity->allowed
                ? PolicyDecision::allow()
                : PolicyDecision::deny('hidden'),
        ));
        $gate = new ReadGate($catalogue, new AnonymousViewerProvider());
        $hydrator = $this->hydrator();
        $registry = $this->createMock(HydratorRegistry::class);
        $registry->method('get')->willReturn($hydrator);
        $loader = new CachingEdgeLoader($storage, $registry, ['Post.comments' => 'Comment'], $gate);

        $result = $loader->preload('Post', [EntityId::of(1), EntityId::of(2)], 'comments');

        self::assertArrayHasKey('1', $result);
        self::assertCount(1, $result['1'] ?? []);
    }

    /** @return Hydrator<object> */
    private function hydrator(): Hydrator
    {
        return new class () implements Hydrator {
            public function hydrate(Record $record, EdgeLoader $edges): object
            {
                return new PolicyEntity((bool) $record->value('allowed'));
            }
        };
    }
}

final class PolicyEntity
{
    public function __construct(public bool $allowed)
    {
    }
}
