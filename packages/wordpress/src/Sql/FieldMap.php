<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

use PheFr\Schema\Ir\Schema;

/**
 * Translates between the spec's field names and the database's column names.
 *
 * The boundary that keeps storage naming inside the adaptor: everything above speaks
 * in fields, everything below in columns, and this is the only thing that knows both.
 * Without it, snake_case column names leak into hydrators and generated code, and the
 * spec stops being the only place a field is named.
 */
final readonly class FieldMap
{
    /** @var array<string, array<string, string>> entity => field => column */
    private array $toColumn;

    /** @var array<string, array<string, string>> entity => column => field */
    private array $toField;

    public function __construct(Schema $schema, Naming $naming = new Naming())
    {
        $toColumn = [];
        $toField = [];

        foreach ($schema->entities as $entity) {
            foreach ($entity->fields as $field) {
                $column = $naming->column($field->name);

                $toColumn[$entity->name][$field->name] = $column;
                $toField[$entity->name][$column] = $field->name;
            }
        }

        $this->toColumn = $toColumn;
        $this->toField = $toField;
    }

    public function column(string $entity, string $field): string
    {
        // An unmapped name is a link column or a projection the adaptor added, and
        // those are already in database form.
        return $this->toColumn[$entity][$field] ?? $field;
    }

    public function field(string $entity, string $column): string
    {
        return $this->toField[$entity][$column] ?? $column;
    }

    /**
     * @param array<string, scalar|null> $values Keyed by field name.
     *
     * @return array<string, scalar|null> Keyed by column name.
     */
    public function toColumns(string $entity, array $values): array
    {
        $columns = [];

        foreach ($values as $field => $value) {
            $columns[$this->column($entity, $field)] = $value;
        }

        return $columns;
    }

    /**
     * @param array<string, scalar|null> $row Keyed by column name.
     *
     * @return array<string, scalar|null> Keyed by field name.
     */
    public function toFields(string $entity, array $row): array
    {
        $fields = [];

        foreach ($row as $column => $value) {
            $fields[$this->field($entity, $column)] = $value;
        }

        return $fields;
    }
}
