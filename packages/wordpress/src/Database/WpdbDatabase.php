<?php

declare(strict_types=1);

namespace PheFr\WordPress\Database;

use RuntimeException;
use wpdb;

/**
 * The only class that touches wpdb.
 *
 * Everything WordPress-shaped about talking to MariaDB is confined here: prepare()'s
 * printf-style placeholders, the array-shaped result rows, and the fact that wpdb
 * reports errors on a property rather than by throwing.
 */
final readonly class WpdbDatabase implements Database
{
    public function __construct(private wpdb $wpdb)
    {
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function select(string $sql, array $bindings = []): array
    {
        // The literal rather than the ARRAY_A constant: WordPress defines its
        // constants at runtime, so they do not exist for static analysis.
        /** @var list<array<string, scalar|null>>|null $rows */
        $rows = $this->wpdb->get_results($this->prepare($sql, $bindings), 'ARRAY_A');

        $this->guard($sql);

        return $rows ?? [];
    }

    public function scalar(string $sql, array $bindings = []): string|int|float|null
    {
        /** @var scalar|null $value */
        $value = $this->wpdb->get_var($this->prepare($sql, $bindings));

        $this->guard($sql);

        return is_bool($value) ? (int) $value : $value;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $affected = $this->wpdb->query($this->prepare($sql, $bindings));

        $this->guard($sql);

        return is_int($affected) ? $affected : 0;
    }

    public function insert(string $table, array $values): int
    {
        $this->wpdb->insert($table, $values);

        $this->guard(sprintf('INSERT INTO %s', $table));

        return (int) $this->wpdb->insert_id;
    }

    public function beginTransaction(): void
    {
        $this->execute('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->execute('COMMIT');
    }

    public function rollBack(): void
    {
        $this->execute('ROLLBACK');
    }

    public function describeTable(string $table): array
    {
        // SHOW COLUMNS errors rather than returning nothing when the table is absent,
        // so the absence is checked first.
        $exists = $this->scalar('SHOW TABLES LIKE %s', [$table]);

        if (null === $exists) {
            return [];
        }

        return $this->select(sprintf('SHOW COLUMNS FROM `%s`', $table));
    }

    public function tablesWithPrefix(string $prefix): array
    {
        $tables = [];

        foreach ($this->select('SHOW TABLES LIKE %s', [$prefix . '%']) as $row) {
            foreach ($row as $value) {
                if (is_string($value)) {
                    $tables[] = $value;
                }
            }
        }

        return $tables;
    }

    /**
     * @param list<scalar|null> $bindings
     */
    private function prepare(string $sql, array $bindings): string
    {
        if ([] === $bindings) {
            return $sql;
        }

        // wpdb::prepare() wants a literal-string, which no compiler can build. Our
        // statements come from QueryCompiler, which resolves identifiers against the
        // table schema and never interpolates a value — values are always placeholders.
        // @phpstan-ignore argument.type
        $prepared = $this->wpdb->prepare($sql, ...$bindings);

        if (!is_string($prepared)) {
            throw new RuntimeException(sprintf('Could not prepare: %s', $sql));
        }

        return $prepared;
    }

    private function guard(string $sql): void
    {
        if ('' === $this->wpdb->last_error) {
            return;
        }

        throw new RuntimeException(sprintf('%s (running: %s)', $this->wpdb->last_error, $sql));
    }
}
