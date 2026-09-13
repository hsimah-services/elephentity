<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

final readonly class Order
{
    private function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {
    }

    public static function ascending(string $field): self
    {
        return new self($field, Direction::Ascending);
    }

    public static function descending(string $field): self
    {
        return new self($field, Direction::Descending);
    }
}
