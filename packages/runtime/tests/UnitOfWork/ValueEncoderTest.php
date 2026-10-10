<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use DateTimeImmutable;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Query\ValueDecoder;
use Eleph\Runtime\UnitOfWork\ValueEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(ValueEncoder::class)]
final class ValueEncoderTest extends TestCase
{
    public function testScalarsAndNullPassStraightThrough(): void
    {
        $encoder = $this->encoder();

        self::assertNull($encoder->encode('Post', 'title', null));
        self::assertSame('Hello', $encoder->encode('Post', 'title', 'Hello'));
        self::assertSame(7, $encoder->encode('Post', 'views', 7));
        self::assertTrue($encoder->encode('Post', 'featured', true));
    }

    public function testABackedEnumStoresItsValueRatherThanItsName(): void
    {
        self::assertSame('draft', $this->encoder()->encode('Post', 'status', TestStatus::Draft));
    }

    public function testDatesUseOneFormatEverywhere(): void
    {
        self::assertSame(
            '2026-09-05 14:30:00',
            $this->encoder()->encode('Post', 'createdAt', new DateTimeImmutable('2026-09-05 14:30:00')),
        );
    }

    public function testADateKeepsItsInstantWhateverTheZones(): void
    {
        // The input's zone and the server's both differ from UTC, and neither may move the instant.
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');

        try {
            $at = new DateTimeImmutable('2026-10-08T12:00:00+02:00');
            $stored = $this->encoder()->encode('Post', 'createdAt', $at);

            self::assertSame('2026-10-08 10:00:00', $stored);
            self::assertSame(
                $at->getTimestamp(),
                (new ValueDecoder())->datetime($stored, 'Post.createdAt')->getTimestamp(),
            );
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAnIdStoresItsRawValue(): void
    {
        self::assertSame(7, $this->encoder()->encode('Post', 'authorId', EntityId::of(7)));
    }

    public function testJsonFieldsAreEncoded(): void
    {
        self::assertSame(
            '{"a":1}',
            $this->encoder()->encode('Post', 'meta', ['a' => 1]),
        );
    }

    public function testAnUnknownObjectSaysWhatIsMissing(): void
    {
        // A declared type without a write processor is the likely cause, so the error
        // says so rather than reporting a generic type failure.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a write processor');

        $this->encoder()->encode('Post', 'price', new stdClass());
    }

    private function encoder(): ValueEncoder
    {
        return new ValueEncoder([], new StubProcessors());
    }
}
