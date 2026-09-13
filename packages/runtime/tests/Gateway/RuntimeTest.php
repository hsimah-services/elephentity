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
    public function testFindThrowsWhenTheReadPolicyDenies(): void
    {
        $storage = $this->createMock(StorageAdaptor::class);
        $storage->method('get')->willReturn(new Record('Post', EntityId::of(1), []));
        $catalogue = $this->createMock(EntityCatalogue::class);
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
