<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Query;

use Eleph\Runtime\Query\ValueDecoder;
use Eleph\Runtime\Tests\UnitOfWork\TestStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The read half of the round trip. Every method fails loudly on the wrong shape: a
 * column holding what the spec says it cannot is a corrupted row or a missed
 * migration, and coercing it silently would bury the evidence.
 */
#[CoversClass(ValueDecoder::class)]
final class ValueDecoderTest extends TestCase
{
    public function testNumericStringsFromTheDriverAreAcceptedAsIntegers(): void
    {
        // MariaDB returns integers as strings over some drivers, so this is the
        // expected shape rather than an error.
        $decoder = new ValueDecoder();

        self::assertSame(7, $decoder->int(7, 'Post.views'));
        self::assertSame(7, $decoder->int('7', 'Post.views'));
        self::assertSame(-7, $decoder->int('-7', 'Post.views'));
    }

    public function testSomethingThatIsNotAnIntegerIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post.views holds a string, but the spec says int.');

        (new ValueDecoder())->int('7.5', 'Post.views');
    }

    public function testBooleansSurviveTheTinyintRoundTrip(): void
    {
        $decoder = new ValueDecoder();

        self::assertTrue($decoder->bool(1, 'Post.featured'));
        self::assertTrue($decoder->bool('1', 'Post.featured'));
        self::assertFalse($decoder->bool(0, 'Post.featured'));
        self::assertFalse($decoder->bool('0', 'Post.featured'));
    }

    public function testDatesRoundTripThroughTheFormatTheyWereWrittenIn(): void
    {
        $decoder = new ValueDecoder();
        $date = $decoder->datetime('2026-09-05 14:30:00', 'Post.createdAt');

        self::assertSame('2026-09-05 14:30:00', $date->format($decoder->datetimeFormat()));
    }

    public function testAnUnparseableDateSaysWhichFieldHoldsIt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post.createdAt holds "not a date", which is not a date.');

        (new ValueDecoder())->datetime('not a date', 'Post.createdAt');
    }

    public function testJsonDecodesToAnArray(): void
    {
        self::assertSame(['a' => 1], (new ValueDecoder())->json('{"a":1}', 'Post.meta'));
    }

    public function testMalformedJsonIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post.meta does not hold valid JSON');

        (new ValueDecoder())->json('{oops', 'Post.meta');
    }

    public function testAnEnumMemberIsResolvedFromItsStoredValue(): void
    {
        self::assertSame(
            TestStatus::Draft,
            (new ValueDecoder())->enum(TestStatus::class, 'draft', 'Post.status'),
        );
    }

    public function testAStoredValueTheEnumNoLongerHasIsAMissedMigration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a member of');

        (new ValueDecoder())->enum(TestStatus::class, 'retired', 'Post.status');
    }
}
