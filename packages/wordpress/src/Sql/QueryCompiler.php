<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

use PheFr\Runtime\Storage\Comparison;
use PheFr\Runtime\Storage\Criteria;
use PheFr\Runtime\Storage\Direction;
use PheFr\Runtime\Storage\EdgeFilter;
use PheFr\Runtime\Storage\Filter;
use RuntimeException;

/**
 * Turns a Criteria into a parameterised SELECT.
 *
 * Values always become placeholders. Identifiers cannot be parameterised, so column
 * names are resolved against the table's own schema rather than escaped — an unknown
 * column is a bug in the caller, and refusing it outright is safer than quoting
 * whatever happened to arrive.
 */
final readonly class QueryCompiler
{
    private const MAX_LIMIT = 1000;

    /**
     * @param array<string, EdgePlacement> $placements Keyed by "Entity.edge".
     */
    public function __construct(
        private Naming $naming = new Naming(),
        private array $placements = [],
    ) {
    }

    public function select(TableSchema $table, Criteria $criteria): CompiledQuery
    {
        $links = $this->links($table, $criteria);
        [$where, $bindings] = $this->where($table, $criteria);

        $sql = sprintf('SELECT `%s`.*%s FROM `%s`', $table->name, $links['projection'], $table->name)
            . $links['join']
            . $this->combine($where, $links['where'])
            . $this->orderBy($table, $criteria)
            . $this->limit($criteria);

        return new CompiledQuery($sql, [...$links['bindings'], ...$bindings]);
    }

    public function count(TableSchema $table, Criteria $criteria): CompiledQuery
    {
        $links = $this->links($table, $criteria);
        [$where, $bindings] = $this->where($table, $criteria);

        return new CompiledQuery(
            sprintf('SELECT COUNT(*) FROM `%s`', $table->name)
                . $links['join']
                . $this->combine($where, $links['where']),
            [...$links['bindings'], ...$bindings],
        );
    }

    private function combine(string $fieldWhere, string $linkWhere): string
    {
        return match (true) {
            '' === $fieldWhere => $linkWhere,
            '' === $linkWhere => $fieldWhere,
            default => $fieldWhere . ' AND ' . substr($linkWhere, strlen(' WHERE ')),
        };
    }

    /**
     * Compile every "linked to" constraint into joins, a where clause, and — when more
     * than one parent is named — the projection that says which parent a row came from.
     *
     * @return array{join: string, where: string, projection: string, bindings: list<scalar|null>}
     */
    private function links(TableSchema $table, Criteria $criteria): array
    {
        $joins = '';
        $clauses = [];
        $projection = '';
        $bindings = [];
        $alias = 0;

        foreach ($criteria->links as $link) {
            $placement = $this->placement($link);
            $ids = array_map(static fn ($id): string => (string) $id, $link->from);
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

            if (!$placement->usesJoinTable() && !$placement->keyIsLocal()) {
                // The key is a column on this very table, so no join is needed.
                $clauses[] = sprintf('`%s`.`%s` IN (%s)', $table->name, $placement->localColumn, $placeholders);

                if ($link->needsParentColumn()) {
                    $projection .= sprintf(
                        ', `%s`.`%s` AS `%s`',
                        $table->name,
                        $placement->localColumn,
                        EdgeFilter::PARENT_COLUMN,
                    );
                }
            } else {
                $on = sprintf('l%d', ++$alias);

                [$joinTable, $joinToTarget, $parentColumn] = $placement->usesJoinTable()
                    ? [$placement->table, (string) $placement->targetColumn, $placement->localColumn]
                    : [$placement->table, 'id', $placement->localColumn];

                // For a locally-keyed edge the declaring table points at us, so the
                // join runs the other way round.
                $joins .= $placement->usesJoinTable()
                    ? sprintf(
                        ' INNER JOIN `%s` `%s` ON `%s`.`%s` = `%s`.`id`',
                        $joinTable,
                        $on,
                        $on,
                        $joinToTarget,
                        $table->name,
                    )
                    : sprintf(
                        ' INNER JOIN `%s` `%s` ON `%s`.`%s` = `%s`.`id`',
                        $joinTable,
                        $on,
                        $on,
                        $placement->localColumn,
                        $table->name,
                    );

                $clauses[] = $placement->usesJoinTable()
                    ? sprintf('`%s`.`%s` IN (%s)', $on, $parentColumn, $placeholders)
                    : sprintf('`%s`.`id` IN (%s)', $on, $placeholders);

                if ($link->needsParentColumn()) {
                    $projection .= $placement->usesJoinTable()
                        ? sprintf(', `%s`.`%s` AS `%s`', $on, $parentColumn, EdgeFilter::PARENT_COLUMN)
                        : sprintf(', `%s`.`id` AS `%s`', $on, EdgeFilter::PARENT_COLUMN);
                }
            }

            foreach ($ids as $id) {
                $bindings[] = $id;
            }
        }

        return [
            'join' => $joins,
            'where' => [] === $clauses ? '' : ' WHERE ' . implode(' AND ', $clauses),
            'projection' => $projection,
            'bindings' => $bindings,
        ];
    }

    private function placement(EdgeFilter $link): EdgePlacement
    {
        return $this->placements[$link->entity . '.' . $link->edge]
            ?? throw new RuntimeException(sprintf(
                'Edge %s.%s has no placement; the schema was not planned.',
                $link->entity,
                $link->edge,
            ));
    }

    /**
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function where(TableSchema $table, Criteria $criteria): array
    {
        if ([] === $criteria->filters) {
            return ['', []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($criteria->filters as $filter) {
            $column = sprintf('`%s`.`%s`', $table->name, $this->resolve($table, $filter->field));

            [$clause, $values] = $this->clause($column, $filter);

            $clauses[] = $clause;

            foreach ($values as $value) {
                $bindings[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function clause(string $column, Filter $filter): array
    {
        $quoted = $column;

        return match ($filter->comparison) {
            Comparison::IsNull => [$quoted . ' IS NULL', []],
            Comparison::IsNotNull => [$quoted . ' IS NOT NULL', []],
            Comparison::In, Comparison::NotIn => $this->setClause($quoted, $filter),
            Comparison::Contains => [
                $quoted . ' LIKE %s',
                [$this->escapeLike($filter->value) . '%'],
            ],
            Comparison::StartsWith => [
                $quoted . ' LIKE %s',
                [$this->escapeLike($filter->value) . '%'],
            ],
            default => [
                sprintf('%s %s %%s', $quoted, $this->operator($filter->comparison)),
                [$this->scalar($filter->value)],
            ],
        };
    }

    /**
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function setClause(string $quoted, Filter $filter): array
    {
        $values = is_array($filter->value) ? array_values($filter->value) : [$filter->value];

        if ([] === $values) {
            // An empty IN () is a syntax error in MySQL, and an empty set matches
            // nothing (or everything, for NOT IN), so say that directly.
            return [Comparison::In === $filter->comparison ? '1 = 0' : '1 = 1', []];
        }

        $placeholders = implode(', ', array_fill(0, count($values), '%s'));

        return [
            sprintf(
                '%s %s (%s)',
                $quoted,
                Comparison::In === $filter->comparison ? 'IN' : 'NOT IN',
                $placeholders,
            ),
            array_map($this->scalar(...), $values),
        ];
    }

    private function operator(Comparison $comparison): string
    {
        return match ($comparison) {
            Comparison::Equals => '=',
            Comparison::NotEquals => '<>',
            Comparison::LessThan => '<',
            Comparison::LessThanOrEqual => '<=',
            Comparison::GreaterThan => '>',
            Comparison::GreaterThanOrEqual => '>=',
            default => throw new RuntimeException(sprintf(
                'Comparison %s has no simple operator.',
                $comparison->value,
            )),
        };
    }

    private function orderBy(TableSchema $table, Criteria $criteria): string
    {
        if ([] === $criteria->order) {
            return '';
        }

        $parts = [];

        foreach ($criteria->order as $order) {
            $parts[] = sprintf(
                '`%s`.`%s` %s',
                $table->name,
                $this->resolve($table, $order->field),
                Direction::Descending === $order->direction ? 'DESC' : 'ASC',
            );
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function limit(Criteria $criteria): string
    {
        if (null === $criteria->limit) {
            return '';
        }

        // A cap rather than trust: an unbounded page is how a lazy query stops being
        // lazy. Callers wanting everything say all() and mean it.
        return sprintf(' LIMIT %d', min(max($criteria->limit, 1), self::MAX_LIMIT));
    }

    private function resolve(TableSchema $table, string $field): string
    {
        $column = $this->naming->column($field);

        if (null === $table->column($column)) {
            throw new RuntimeException(sprintf(
                'Table %s has no column for field "%s".',
                $table->name,
                $field,
            ));
        }

        return $column;
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if (null === $value || is_scalar($value)) {
            return $value;
        }

        throw new RuntimeException('Filter values must be scalar.');
    }

    private function escapeLike(mixed $value): string
    {
        return addcslashes((string) $this->scalar($value), '%_\\');
    }
}
