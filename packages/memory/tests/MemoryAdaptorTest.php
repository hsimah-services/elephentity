<?php

declare(strict_types=1);

namespace Eleph\Memory\Tests;

use Eleph\Memory\MemoryAdaptor;
use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Storage\Testing\AdaptorConformance;
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
}
