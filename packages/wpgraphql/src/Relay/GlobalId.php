<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Relay;

use Eleph\Runtime\Identity\EntityId;
use InvalidArgumentException;
use Stringable;

/**
 * The opaque identifier a Node hands out, and the row id hiding inside it.
 *
 * `id: 12` is unique to a table and not to a schema, so a client with a normalised
 * cache files a Post and an Author of the same row number in one place and serves the
 * wrong one. The Node interface exists to stop exactly that, and the price of
 * implementing it is that the type name has to travel with the id.
 *
 * The shape is WPGraphQL's: `base64(loaderKey:id)`, split at the first colon. What
 * WPGraphQL calls the loader key is a single constant here rather than the type name,
 * because AppContext builds a loader from a class name alone — there is no constructor
 * through which a shared class could be told which type it was registered for, and a
 * generated loader class per entity would be one more tree to keep in step with the
 * spec. So the key says only "this is ours", and the type name is the next segment.
 */
final readonly class GlobalId implements Stringable
{
    /** The WPGraphQL data loader every Elephentity node resolves through. */
    public const LOADER = 'eleph';

    private const DELIMITER = ':';

    public function __construct(
        public string $type,
        public string $id,
    ) {
    }

    /**
     * `base64('eleph:ClogItem:12')`.
     */
    public static function encode(string $type, string|int $id): string
    {
        return base64_encode(implode(self::DELIMITER, [self::LOADER, $type, (string) $id]));
    }

    /**
     * Back to its parts, or null when the string is not one of ours.
     *
     * Null rather than an exception because callers pair this with a fallback: a
     * client holding a raw database id from before Node existed, or a spec field
     * genuinely typed `id`, should keep working rather than fail on a value that was
     * never meant to be decoded.
     */
    public static function decode(string $encoded): ?self
    {
        $decoded = base64_decode($encoded, true);

        if (false === $decoded) {
            return null;
        }

        $parts = explode(self::DELIMITER, $decoded, 3);

        return 3 === count($parts) && self::LOADER === $parts[0] && '' !== $parts[1] && '' !== $parts[2]
            ? new self($parts[1], $parts[2])
            : null;
    }

    /**
     * The half WPGraphQL hands a loader: everything after the loader key.
     */
    public static function fromLoaderKey(string $key): ?self
    {
        $parts = explode(self::DELIMITER, $key, 2);

        return 2 === count($parts) && '' !== $parts[0] && '' !== $parts[1]
            ? new self($parts[0], $parts[1])
            : null;
    }

    /**
     * The id a client sent, whichever shape it is in.
     *
     * Null when there is no usable id at all, so the caller decides whether that is a
     * missing argument or a row that cannot exist.
     */
    public static function entityId(mixed $value): ?EntityId
    {
        $raw = self::raw($value);

        if (!is_int($raw) && !is_string($raw)) {
            return null;
        }

        try {
            return EntityId::of($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A global id unwrapped, anything else left exactly as it arrived.
     */
    public static function raw(mixed $value): mixed
    {
        $decoded = is_string($value) ? self::decode($value) : null;

        return null === $decoded ? $value : $decoded->id;
    }

    public function loaderKey(): string
    {
        return $this->type . self::DELIMITER . $this->id;
    }

    public function __toString(): string
    {
        return self::encode($this->type, $this->id);
    }
}
