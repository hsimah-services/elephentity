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
     * A row id, as the globally unique one the Node interface promises.
     *
     * `12` identifies a row within its table and nothing beyond it, so a client with a
     * normalised cache would file two entities of the same row number in one place.
     * The type name travels with the id to stop that; `databaseId` keeps the raw one
     * for anything that has to address the row outside GraphQL.
     */
    case GlobalId = 'global_id';

    /**
     * A row id, as the string GraphQL's ID scalar wants.
     *
     * EntityId is opaque above the storage layer and prints rather than casts. The ID
     * scalar happens to accept anything that prints, but leaning on that is exactly
     * the implicit conversion the rest of these cases exist to remove.
     */
    case Id = 'id';

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
