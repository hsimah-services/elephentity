<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

final readonly class ReturnDefinition
{
    public function __construct(
        public string $type,
        public Cardinality $cardinality = Cardinality::Many,
    ) {
    }
}
