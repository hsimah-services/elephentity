<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

/**
 * One condition on a field. Filters within a criteria are conjunctive.
 */
final readonly class Filter
{
    /**
     * @param scalar|list<scalar>|null $value
     */
    private function __construct(
        public string $field,
        public Comparison $comparison,
        public string|int|float|bool|array|null $value = null,
    ) {
    }

    public static function equals(string $field, string|int|float|bool $value): self
    {
        return new self($field, Comparison::Equals, $value);
    }

    public static function notEquals(string $field, string|int|float|bool $value): self
    {
        return new self($field, Comparison::NotEquals, $value);
    }

    public static function lessThan(string $field, string|int|float|bool $value): self
    {
        return new self($field, Comparison::LessThan, $value);
    }

    public static function lessThanOrEqual(string $field, string|int|float|bool $value): self
    {
        return new self($field, Comparison::LessThanOrEqual, $value);
    }

    public static function greaterThan(string $field, string|int|float|bool $value): self
    {
        return new self($field, Comparison::GreaterThan, $value);
    }

    public static function greaterThanOrEqual(string $field, string|int|float|bool $value): self
    {
        return new self($field, Comparison::GreaterThanOrEqual, $value);
    }

    /**
     * @param list<scalar> $value
     */
    public static function in(string $field, array $value): self
    {
        return new self($field, Comparison::In, $value);
    }

    /**
     * @param list<scalar> $value
     */
    public static function notIn(string $field, array $value): self
    {
        return new self($field, Comparison::NotIn, $value);
    }

    public static function contains(string $field, string $value): self
    {
        return new self($field, Comparison::Contains, $value);
    }

    public static function startsWith(string $field, string $value): self
    {
        return new self($field, Comparison::StartsWith, $value);
    }

    public static function isNull(string $field): self
    {
        return new self($field, Comparison::IsNull);
    }

    public static function isNotNull(string $field): self
    {
        return new self($field, Comparison::IsNotNull);
    }
}
