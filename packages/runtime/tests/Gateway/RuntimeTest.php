<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Gateway;

use Eleph\Runtime\Catalogue\EntityCatalogue;
use Eleph\Runtime\Gateway\Runtime;
use Eleph\Runtime\Gateway\UnitOfWorkFactory;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Policy\AnonymousViewerProvider;
use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Policy\WriteGate;
use Eleph\Runtime\Query\EdgeLoader;
use Eleph\Runtime\Query\Hydrator;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Tests\Policy\StubPolicies;
use Eleph\Runtime\Type\NullProcessorRegistry;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RuntimeTest extends TestCase
{
    public function testDeclaredQueriesPreserveTheirCardinalityAndNamedArguments(): void
    {
        $storage = $this->createStub(StorageAdaptor::class);
        $catalogue = $this->createStub(EntityCatalogue::class);
        $collection = $this->createStub(\Eleph\Runtime\Query\EntityQuery::class);
        $finder = new class ($collection) {
            public function __construct(private object $collection)
            {
            }
            public function bySlug(string $slug): ?object
            {
                return 'visible' === $slug ? (object) ['slug' => $slug] : null;
            }
            public function search(string $slug): object
            {
                return $this->collection;
            }
        };
        $catalogue->method('finder')->willReturn($finder);
        $catalogue->method('queryArguments')->willReturn(['slug']);
        $viewer = new AnonymousViewerProvider();
        $runtime = new Runtime($storage, $catalogue, new UnitOfWorkFactory($storage, $catalogue, new NullProcessorRegistry()), new ReadGate($catalogue, $viewer), new WriteGate($catalogue, $viewer));
        self::assertEquals((object) ['slug' => 'visible'], $runtime->runQuery('Page', 'bySlug', ['slug' => 'visible']));
        self::assertNull($runtime->runQuery('Page', 'bySlug', ['slug' => 'missing']));
        self::assertNull($runtime->runQuery('Page', 'bySlug', ['slug' => 'draft']));
        self::assertSame($collection, $runtime->runQuery('Page', 'search', ['slug' => 'visible']));
    }

    public function testFindThrowsWhenTheReadPolicyDenies(): void
    {
        $storage = $this->createStub(StorageAdaptor::class);
        $storage->method('get')->willReturn(new Record('Post', EntityId::of(1), []));
        $catalogue = $this->createStub(EntityCatalogue::class);
        $catalogue->method('hydrator')->willReturn(new class () implements Hydrator {
            public function hydrate(Record $record, EdgeLoader $edges): object
            {
                return new stdClass();
            }
        });
        $catalogue->method('edgeTargets')->willReturn([]);
        $catalogue->method('readPolicies')->willReturn(new StubPolicies(
            static fn (): \Eleph\Runtime\Policy\PolicyDecision => \Eleph\Runtime\Policy\PolicyDecision::deny('hidden'),
        ));
        $viewer = new AnonymousViewerProvider();
        $runtime = new Runtime(
            $storage,
            $catalogue,
            new UnitOfWorkFactory($storage, $catalogue, new NullProcessorRegistry()),
            new ReadGate($catalogue, $viewer),
            new WriteGate($catalogue, $viewer),
        );

        $this->expectException(\Eleph\Runtime\Policy\AccessDenied::class);
        $runtime->find('Post', EntityId::of(1));
    }
}
