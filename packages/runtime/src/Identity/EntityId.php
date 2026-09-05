<?php

declare(strict_types=1);

namespace PheFr\Runtime\Identity;

use InvalidArgumentException;

/**
 * The identity of a persisted row, opaque above the storage layer.
 *
 * The underlying value is currently a WordPress-style auto-increment integer, but
 * nothing outside the adaptor may do arithmetic on it or assume it is numeric — the
 * entity exposes this object and GraphQL exposes a string. Keeping it opaque is what
 * makes a later move to UUIDv7 a change to the adaptor and a migration, rather than a
 * change to every consumer.
 */
final readonly class EntityId implements Identifier
{
    private function __construct(private int|string $value)
    {
    }

    public static function of(int|string $value): self
    {
        if (is_string($value) && '' === trim($value)) {
            throw new InvalidArgumentException('An entity id cannot be an empty string.');
        }

        if (is_int($value) && $value < 1) {
            throw new InvalidArgumentException(sprintf('An entity id cannot be %d.', $value));
        }

        return new self($value);
    }

    /**
     * The raw stored value.
     *
     * For storage adaptors only: everything above them treats an id as opaque.
     */
    public function raw(): int|string
    {
        return $this->value;
    }

    public function isPersisted(): bool
    {
        return true;
    }

    public function equals(Identifier $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
