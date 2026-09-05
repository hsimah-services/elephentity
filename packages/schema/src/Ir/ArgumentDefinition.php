<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

final readonly class ArgumentDefinition
{
    public function __construct(
        public string $name,
        public TypeReference $type,
        public bool $nullable = false,
    ) {
    }
}
