<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use BackedEnum;
use DateTimeImmutable;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\UnitOfWork\ValueEncoder;
use Exception;
use JsonException;
use RuntimeException;

/**
 * Turns stored primitives back into domain values.
 *
 * The mirror of ValueEncoder, and the reason generated hydrators stay short: the
 * coercion rules live here and are tested once, rather than being emitted into every
 * entity.
 *
 * Every method fails loudly on the wrong shape. A column holding something the spec
 * says it cannot is a corrupted row or a missed migration, and continuing with a
 * silently coerced value would bury the evidence.
 */
final readonly class ValueDecoder
{
    public function string(mixed $value, string $field): string
    {
        return is_string($value) ? $value : $this->reject($value, 'string', $field);
    }

    public function int(mixed $value, string $field): int
    {
        // MariaDB hands integers back as strings over some drivers, so a numeric
        // string is the expected shape rather than an error.
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && 1 === preg_match('/^-?\d+$/', $value)
            ? (int) $value
            : $this->reject($value, 'int', $field);
    }

    public function float(mixed $value, string $field): float
    {
        return is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : $this->reject($value, 'float', $field);
    }

    public function bool(mixed $value, string $field): bool
    {
        return match (true) {
            is_bool($value) => $value,
            1 === $value, '1' === $value => true,
            0 === $value, '0' === $value, '' === $value => false,
            default => $this->reject($value, 'bool', $field),
        };
    }

    public function datetime(mixed $value, string $field): DateTimeImmutable
    {
        $raw = $this->string($value, $field);

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf('%s holds "%s", which is not a date.', $field, $raw),
                previous: $exception,
            );
        }
    }

    public function id(mixed $value, string $field): EntityId
    {
        return is_int($value) || is_string($value)
            ? EntityId::of($value)
            : $this->reject($value, 'id', $field);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(mixed $value, string $field): array
    {
        try {
            $decoded = json_decode($this->string($value, $field), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('%s does not hold valid JSON: %s', $field, $exception->getMessage()),
                previous: $exception,
            );
        }

        return is_array($decoded) ? $decoded : $this->reject($value, 'json object', $field);
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    public function enum(string $enum, mixed $value, string $field): BackedEnum
    {
        if (!is_string($value) && !is_int($value)) {
            $this->reject($value, $enum, $field);
        }

        $case = $enum::tryFrom($value);

        if (null === $case) {
            // A value the enum no longer has is a migration that did not happen.
            throw new RuntimeException(sprintf(
                '%s holds "%s", which is not a member of %s.',
                $field,
                $value,
                $enum,
            ));
        }

        return $case;
    }

    /**
     * The format dates are written in, so a round trip is exact.
     */
    public function datetimeFormat(): string
    {
        return ValueEncoder::DATETIME_FORMAT;
    }

    /**
     * @return never
     */
    private function reject(mixed $value, string $expected, string $field): mixed
    {
        throw new RuntimeException(sprintf(
            '%s holds a %s, but the spec says %s.',
            $field,
            get_debug_type($value),
            $expected,
        ));
    }
}
