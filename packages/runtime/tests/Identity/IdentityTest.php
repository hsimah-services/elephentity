<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Identity;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityId::class)]
#[CoversClass(PendingId::class)]
final class IdentityTest extends TestCase
{
    public function testAPersistedIdComparesByValue(): void
    {
        self::assertTrue(EntityId::of(7)->equals(EntityId::of(7)));
        self::assertFalse(EntityId::of(7)->equals(EntityId::of(8)));
    }

    public function testAPersistedIdRendersAsAString(): void
    {
        // Opaque above the storage layer: consumers see a string, never an integer.
        self::assertSame('7', (string) EntityId::of(7));
        self::assertSame('7', (string) EntityId::of('7'));
    }

    public function testAnIdCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntityId::of('  ');
    }

    public function testAnAutoIncrementIdCannotBeZeroOrNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntityId::of(0);
    }

    public function testAPendingIdIsOnlyEqualToItself(): void
    {
        $first = new PendingId('Post');
        $second = new PendingId('Post');

        self::assertTrue($first->equals($first));
        self::assertFalse($first->equals($second));
        self::assertFalse($first->isPersisted());
    }

    public function testAPendingIdNamesItsEntity(): void
    {
        self::assertStringStartsWith('pending:Post#', (string) new PendingId('Post'));
    }

    public function testAPendingIdIsNeverEqualToAPersistedOne(): void
    {
        self::assertFalse((new PendingId('Post'))->equals(EntityId::of(1)));
        self::assertFalse(EntityId::of(1)->equals(new PendingId('Post')));
    }
}
