<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\WPGraphQL\Relay\GlobalId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalId::class)]
final class GlobalIdTest extends TestCase
{
    public function testAnIdCarriesItsTypeSoTwoTablesCannotCollide(): void
    {
        // The whole reason Node exists: row 12 of two tables is one cache entry
        // otherwise, and a client serves the wrong one.
        self::assertNotSame(GlobalId::encode('Post', 12), GlobalId::encode('Author', 12));
    }

    public function testTheRoundTripIsExact(): void
    {
        $decoded = GlobalId::decode(GlobalId::encode('ClogItem', 12));

        self::assertNotNull($decoded);
        self::assertSame('ClogItem', $decoded->type);
        self::assertSame('12', $decoded->id);
    }

    public function testTheEncodingIsTheOneWPGraphQLSplits(): void
    {
        // WPGraphQL decodes a global id itself and hands the loader everything after
        // the first colon, so the loader key has to be the first segment.
        self::assertSame('eleph:ClogItem:12', base64_decode(GlobalId::encode('ClogItem', 12), true));

        $key = GlobalId::fromLoaderKey('ClogItem:12');

        self::assertNotNull($key);
        self::assertSame('ClogItem', $key->type);
        self::assertSame('12', $key->id);
        self::assertSame('ClogItem:12', $key->loaderKey());
    }

    public function testAnIdWithAColonInItSurvives(): void
    {
        // Only the first two delimiters are structural; a UUID-ish or namespaced id
        // keeps whatever is in it.
        $decoded = GlobalId::decode(GlobalId::encode('ClogItem', 'urn:thing:9'));

        self::assertNotNull($decoded);
        self::assertSame('urn:thing:9', $decoded->id);
    }

    /**
     * @return list<array{string}>
     */
    public static function notOurs(): array
    {
        return [['12'], ['1'], [''], ['not base64 at all'], [base64_encode('post:12')]];
    }

    #[DataProvider('notOurs')]
    public function testAnythingElseDecodesToNothingRatherThanGuessing(string $value): void
    {
        // The fallback the registrars rely on: a value that is not one of ours is
        // passed through as it arrived rather than mangled into a row id.
        self::assertNull(GlobalId::decode($value));
        self::assertSame($value, GlobalId::raw($value));
    }

    public function testAnEntityIdIsTakenFromEitherShape(): void
    {
        self::assertSame('12', (string) GlobalId::entityId(GlobalId::encode('Post', 12)));
        self::assertSame('12', (string) GlobalId::entityId('12'));
        self::assertSame('12', (string) GlobalId::entityId(12));
    }

    public function testAnUnusableIdIsNullRatherThanAnException(): void
    {
        // EntityId refuses an empty string and a non-positive int. The caller decides
        // whether that is a missing argument or a row that cannot exist.
        self::assertNull(GlobalId::entityId(null));
        self::assertNull(GlobalId::entityId(''));
        self::assertNull(GlobalId::entityId(0));
        self::assertNull(GlobalId::entityId(['12']));
    }
}
