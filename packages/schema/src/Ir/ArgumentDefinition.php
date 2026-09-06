<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

final readonly class ArgumentDefinition
{
    public function __construct(
        public string $name,
        public TypeReference $type,
        public bool $nullable = false,
    ) {
    }
}
