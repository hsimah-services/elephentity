<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Policy;

use Eleph\Runtime\Catalogue\EntityCatalogue;
use Eleph\Runtime\Policy\AccessDenied;
use Eleph\Runtime\Policy\AnonymousViewerProvider;
use Eleph\Runtime\Policy\PendingWrite;
use Eleph\Runtime\Policy\PolicyDecision;
use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Policy\WriteGate;
use Eleph\Runtime\Policy\WriteOperation;
use PHPUnit\Framework\TestCase;
use stdClass;

final class PolicyGateTest extends TestCase
{
    public function testAllowWinsAndUngatedEntitiesDoNotRunPolicies(): void
    {
        $policies = new StubPolicies(static fn (): PolicyDecision => PolicyDecision::allow());
        $catalogue = $this->createMock(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturnMap([
            ['Post', $policies],
            ['PublicPost', new \Eleph\Runtime\Policy\NoPolicies()],
        ]);
        $gate = new ReadGate($catalogue, new AnonymousViewerProvider());
        $object = new stdClass();

        self::assertSame($object, $gate->permit('Post', $object));
        self::assertSame($object, $gate->permit('PublicPost', $object));
        self::assertSame(1, $policies->calls);
    }

    public function testDenyAndSkipAreDeniedAtTheGate(): void
    {
        $policies = new StubPolicies(static fn (): PolicyDecision => PolicyDecision::deny('Not allowed.'));
        $catalogue = $this->createMock(EntityCatalogue::class);
        $catalogue->method('readPolicies')->willReturn($policies);
        $gate = new ReadGate($catalogue, new AnonymousViewerProvider());

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('Post: Not allowed.');
        $gate->permit('Post', new stdClass());
    }

    public function testWriteGateRunsBeforeTheCallerMutation(): void
    {
        $policies = new StubWritePolicies(static function (): PolicyDecision {
            return PolicyDecision::deny('Writes are closed.');
        });
        $catalogue = $this->createMock(EntityCatalogue::class);
        $catalogue->method('writePolicies')->willReturn($policies);
        $gate = new WriteGate($catalogue, new AnonymousViewerProvider());

        $this->expectException(AccessDenied::class);
        $gate->permit('Post', null, new PendingWrite('Post', WriteOperation::Create, null, [], null));
    }
}
