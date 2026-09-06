<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

final readonly class ReturnDefinition
{
    public function __construct(
        public string $type,
        public Cardinality $cardinality = Cardinality::Many,
    ) {
    }
}
