<?php

declare(strict_types=1);

namespace Eleph\SQLite\Sql;


final readonly class CompiledQuery
{
    /**
     * @param list<scalar|null> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {
    }
}
