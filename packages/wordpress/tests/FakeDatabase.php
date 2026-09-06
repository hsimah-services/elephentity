<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\WordPress\Database\Database;

/**
 * Records what the adaptor asked for, and answers with whatever the test set up.
 *
 * The adaptor is deliberately thin — the decisions live in SchemaBuilder and
 * QueryCompiler, which are pure — so what is worth checking here is dispatch and the
 * transaction boundary, not SQL semantics.
 */
final class FakeDatabase implements Database
{
    /** @var list<array{sql: string, bindings: list<scalar|null>}> */
    public array $statements = [];

    /** @var list<string> */
    public array $transactionLog = [];

    /** @var list<array{table: string, values: array<string, scalar|null>}> */
    public array $inserts = [];

    public int $nextInsertId = 1;

    /** @var list<array<string, scalar|null>> */
    public array $rows = [];

    public string|int|float|null $scalarResult = 0;

    public function prefix(): string
    {
        return 'wp_';
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->statements[] = ['sql' => $sql, 'bindings' => $bindings];

        return $this->rows;
    }

    public function scalar(string $sql, array $bindings = []): string|int|float|null
    {
        $this->statements[] = ['sql' => $sql, 'bindings' => $bindings];

        return $this->scalarResult;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->statements[] = ['sql' => $sql, 'bindings' => $bindings];

        return 1;
    }

    public function insert(string $table, array $values): int
    {
        $this->inserts[] = ['table' => $table, 'values' => $values];

        return $this->nextInsertId++;
    }

    public function beginTransaction(): void
    {
        $this->transactionLog[] = 'begin';
    }

    public function commit(): void
    {
        $this->transactionLog[] = 'commit';
    }

    public function rollBack(): void
    {
        $this->transactionLog[] = 'rollback';
    }

    public function describeTable(string $table): array
    {
        return [];
    }

    public function tablesWithPrefix(string $prefix): array
    {
        return [];
    }
}
