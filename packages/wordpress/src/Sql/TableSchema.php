<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

/**
 * One table as the spec describes it.
 *
 * Derived, never authored: the spec is the source of truth and this is what it means
 * in MariaDB terms.
 */
final readonly class TableSchema
{
    /**
     * @param array<string, Column> $columns Keyed by column name, in declaration order.
     * @param array<string, Index>  $indexes Keyed by index name.
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes = [],
        public string $primaryKey = 'id',
    ) {
    }

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    public function withColumn(Column $column): self
    {
        return new self(
            $this->name,
            [...$this->columns, $column->name => $column],
            $this->indexes,
            $this->primaryKey,
        );
    }

    public function withIndex(Index $index): self
    {
        return new self(
            $this->name,
            $this->columns,
            [...$this->indexes, $index->name => $index],
            $this->primaryKey,
        );
    }
}
