<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

final readonly class Order
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {
    }
}
