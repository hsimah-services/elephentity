<?php

declare(strict_types=1);

namespace Eleph\SQLite;

use PDO;
use PDOStatement;
use Throwable;
use InvalidArgumentException;

/** PDO connection shared by entity storage, application queries and migrations. */
final class Database
{
    public readonly PDO $pdo;
    private int $depth = 0;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 3000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = FULL');
        $this->pdo->exec('PRAGMA cache_size = -2048');
        $this->pdo->sqliteCreateCollation('CLOG_NOCASE', static fn ($a, $b) => strcmp(mb_strtolower($a), mb_strtolower($b)));
    }

    public function prefix(): string { return 'app_'; }

    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new InvalidArgumentException('Invalid SQL identifier.');
        return '"' . $name . '"';
    }

    private function statement(string $sql, array $bindings): PDOStatement
    {
        // Transitional Eleph SQL uses wpdb-style placeholders. Values never enter SQL.
        $sql = preg_replace('/%[sdf]/', '?', $sql);
        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($bindings) as $i => $value) {
            $stmt->bindValue($i + 1, $value, match (true) {
                null === $value => PDO::PARAM_NULL,
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
        $stmt->execute();
        return $stmt;
    }

    public function select(string $sql, array $bindings = []): array { return $this->statement($sql, $bindings)->fetchAll(); }
    public function scalar(string $sql, array $bindings = []): string|int|float|null
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();
        return false === $value ? null : $value;
    }
    public function execute(string $sql, array $bindings = []): int { return $this->statement($sql, $bindings)->rowCount(); }
    public function insert(string $table, array $values): int
    {
        $columns = implode(', ', array_map(self::identifier(...), array_keys($values)));
        $sql = 'INSERT INTO ' . self::identifier($table) . ($values ? ' (' . $columns . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')' : ' DEFAULT VALUES');
        $this->execute($sql, array_values($values));
        return (int) $this->pdo->lastInsertId();
    }
    public function transaction(callable $work): mixed
    {
        $level = $this->depth;
        $this->pdo->exec($level === 0 ? 'BEGIN IMMEDIATE' : 'SAVEPOINT eleph_' . $level);
        ++$this->depth;
        try {
            $result = $work();
            $this->pdo->exec($level === 0 ? 'COMMIT' : 'RELEASE SAVEPOINT eleph_' . $level);
            return $result;
        } catch (Throwable $error) {
            $this->pdo->exec($level === 0 ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT eleph_' . $level);
            if ($level > 0) $this->pdo->exec('RELEASE SAVEPOINT eleph_' . $level);
            throw $error;
        } finally {
            --$this->depth;
        }
    }
}
