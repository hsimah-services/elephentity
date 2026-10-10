<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

use InvalidArgumentException;
use Stringable;

/**
 * An opaque position in a result set.
 *
 * Opaque on purpose: the encoding is the adaptor's business, and a client that parses
 * a cursor becomes coupled to the storage backend's ordering strategy. Callers pass
 * one back exactly as they received it.
 *
 * Opaque is not secret: a cursor is encoded, not encrypted. Keyset paging (#102) will
 * put the last row's sort values in it, so order only by fields the viewer may read.
 */
final readonly class Cursor implements Stringable
{
    private function __construct(private string $token)
    {
    }

    public static function of(string $token): self
    {
        if ('' === $token) {
            throw new InvalidArgumentException('A cursor cannot be empty.');
        }

        return new self($token);
    }

    public function __toString(): string
    {
        return $this->token;
    }
}
