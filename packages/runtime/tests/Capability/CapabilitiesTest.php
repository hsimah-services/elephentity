<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Capability;

use Eleph\Runtime\Capability\Capabilities;
use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Capability\UnsupportedCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Capabilities::class)]
#[CoversClass(UnsupportedCapability::class)]
final class CapabilitiesTest extends TestCase
{
    public function testRequiringAnUnsupportedCapabilityNamesTheAdaptor(): void
    {
        $capabilities = new Capabilities('wordpress', Capability::Transactions);

        $this->expectException(UnsupportedCapability::class);
        $this->expectExceptionMessage('The wordpress storage adaptor does not support faceting.');

        $capabilities->require(Capability::Faceting);
    }

    public function testRequiringASupportedCapabilityIsSilent(): void
    {
        $capabilities = new Capabilities('wordpress', Capability::Transactions);

        $capabilities->require(Capability::Transactions);

        self::assertTrue($capabilities->supports(Capability::Transactions));
        self::assertFalse($capabilities->supports(Capability::ForeignKeys));
    }

    public function testCapabilitiesAreListedInDeclarationOrderOfTheEnum(): void
    {
        $capabilities = new Capabilities(
            'wordpress',
            Capability::RowLocking,
            Capability::Transactions,
        );

        self::assertSame(
            [Capability::Transactions, Capability::RowLocking],
            $capabilities->all(),
        );
    }
}
