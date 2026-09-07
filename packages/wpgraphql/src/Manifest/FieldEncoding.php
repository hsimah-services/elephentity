<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * How a field's domain value becomes the type the manifest declared.
 *
 * The read model is exactly typed — `getCreatedAt()` returns a DateTimeImmutable and
 * `getDefaultExpiryUnit()` returns a backed enum — while GraphQL was told `String` and
 * an enum type. Something has to bridge the two, and the manifest is where it belongs:
 * the same compilation that decided the wire type decides how to reach it, so the pair
 * cannot drift.
 *
 * Handing the value straight over is the common case and stays free.
 */
enum FieldEncoding: string
{
    /** A scalar the accessor already returns in wire form. */
    case Value = 'value';

    /**
     * ISO 8601, not the storage format.
     *
     * Storage wants a sortable column and gets `Y-m-d H:i:s` from ValueEncoder; a
     * client wants an offset it can parse, and every GraphQL client parses ATOM.
     * DateTimeImmutable reads it back unchanged, so the round trip is exact.
     */
    case Datetime = 'datetime';

    /** A backed enum travels as its backing value, which is what the enum type maps. */
    case BackedEnum = 'enum';

    /** A json field is an array in PHP and a String over the wire. */
    case Json = 'json';

    /**
     * A declared value type, unwound by its write processor.
     *
     * Money is an Int over the wire and a Money object on the entity, and the only
     * thing that knows how to get from one to the other is the processor the
     * application already wrote for the mutation path.
     */
    case Processor = 'processor';
}
