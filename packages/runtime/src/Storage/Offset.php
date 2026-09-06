<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

/**
 * A cursor carrying a row offset.
 *
 * Offset paging, deliberately, and worth being honest about: if rows are inserted or
 * removed between two pages, a reader can see a row twice or miss one. Keyset paging
 * avoids that but needs the ordering columns in the cursor and a stable total order,
 * which the spec does not yet require anyone to declare.
 *
 * For the table this exists to serve — page 1, 2, 3 of a list someone is looking at —
 * offset is the right trade. The cursor stays opaque, so replacing it later changes
 * nothing above the adaptor.
 */
final readonly class Offset
{
    private const PREFIX = 'offset:';

    public function __construct(public int $value)
    {
    }

    public function toCursor(): Cursor
    {
        return Cursor::of(base64_encode(self::PREFIX . $this->value));
    }

    /**
     * Zero for anything unreadable: a cursor from an older format should restart the
     * list rather than fail a request someone is watching.
     */
    public static function fromCursor(?Cursor $cursor): self
    {
        if (null === $cursor) {
            return new self(0);
        }

        $decoded = base64_decode((string) $cursor, true);

        if (false === $decoded || !str_starts_with($decoded, self::PREFIX)) {
            return new self(0);
        }

        $value = substr($decoded, strlen(self::PREFIX));

        return new self(1 === preg_match('/^\d+$/', $value) ? (int) $value : 0);
    }
}
