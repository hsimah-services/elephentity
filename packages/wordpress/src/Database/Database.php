<?php

declare(strict_types=1);

namespace Eleph\WordPress\Database;

/**
 * The narrow slice of wpdb this adaptor uses.
 *
 * An interface rather than reaching for the global, so the adaptor is testable without
 * a WordPress installation and so the wpdb-specific parts stay in one small class.
 */
interface Database
{
    public function prefix(): string;

    /**
     * @param list<scalar|null> $bindings
     *
     * @return list<array<string, scalar|null>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * @param list<scalar|null> $bindings
     */
    public function scalar(string $sql, array $bindings = []): string|int|float|null;

    /**
     * @param list<scalar|null> $bindings
     *
     * @return int Rows affected.
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * @param array<string, scalar|null> $values
     *
     * @return int The auto-increment id assigned.
     */
    public function insert(string $table, array $values): int;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Column definitions for one table, or an empty list when it does not exist.
     *
     * @return list<array<string, scalar|null>>
     */
    public function describeTable(string $table): array;

    /**
     * @return list<string>
     */
    public function tablesWithPrefix(string $prefix): array;
}
