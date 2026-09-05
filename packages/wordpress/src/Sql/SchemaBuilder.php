<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

use PheFr\Schema\Ir\EdgeDefinition;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\FieldDefinition;
use PheFr\Schema\Ir\Primitive;
use PheFr\Schema\Ir\Schema;
use RuntimeException;

/**
 * Derives the physical schema from the compiled spec.
 *
 * Custom tables with real typed columns, rather than posts and postmeta. Postmeta is
 * the WordPress-native path and it is an untyped key-value store: it cannot be
 * indexed usefully, cannot express a multi-column query without pain, and degrades
 * badly with volume. A post type is registered alongside only where the WP ecosystem
 * genuinely needs one.
 *
 * Edges are placed here rather than declared: one-to-many puts the key on the far
 * side, many-to-many derives a join table. That means an edge declared on Post can
 * add a column to the comment table, which is why the whole schema is built at once
 * rather than one entity at a time.
 */
final readonly class SchemaBuilder
{
    private const DEFAULT_STRING_LENGTH = 255;

    public function __construct(private Naming $naming = new Naming())
    {
    }

    /**
     * @return array<string, TableSchema> Keyed by table name.
     */
    public function build(Schema $schema): array
    {
        $tables = [];

        foreach ($schema->entities as $entity) {
            $tables[$this->naming->table($entity)] = $this->entityTable($schema, $entity);
        }

        // Edges are resolved second: a one-to-many edge on Post adds a column to the
        // comment table, which must already exist.
        foreach ($schema->entities as $entity) {
            foreach ($entity->edges as $edge) {
                $this->applyEdge($schema, $tables, $entity, $edge);
            }
        }

        ksort($tables);

        return $tables;
    }

    private function entityTable(Schema $schema, EntityDefinition $entity): TableSchema
    {
        $table = $this->naming->table($entity);

        // Every entity has an implicit id. Auto-increment for now: WordPress-standard
        // and unblocking, at the cost of not knowing an id before insert.
        $columns = [
            'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
        ];

        $indexes = [];

        foreach ($entity->fields as $field) {
            $name = $this->naming->column($field->name);

            if (isset($columns[$name])) {
                throw new RuntimeException(sprintf(
                    'Fields %s.%s and another map to the same column "%s".',
                    $entity->name,
                    $field->name,
                    $name,
                ));
            }

            $columns[$name] = new Column($name, $this->columnType($schema, $field), $field->nullable);

            if ($field->unique) {
                $index = new Index($this->naming->uniqueName($table, $name), [$name], unique: true);
                $indexes[$index->name] = $index;

                continue;
            }

            if ($field->indexed) {
                $index = new Index($this->naming->indexName($table, $name), [$name]);
                $indexes[$index->name] = $index;
            }
        }

        return new TableSchema($table, $columns, $indexes);
    }

    /**
     * @param array<string, TableSchema> $tables
     */
    private function applyEdge(
        Schema $schema,
        array &$tables,
        EntityDefinition $entity,
        EdgeDefinition $edge,
    ): void {
        $target = $schema->entity($edge->to);

        if (null === $target) {
            return;
        }

        $relation = $edge->relation();

        if ($relation->needsJoinTable()) {
            $tables[$this->naming->joinTable($entity, $edge)] = $this->joinTable($entity, $target, $edge);

            return;
        }

        // The key sits on whichever side has at most one of the other.
        [$owner, $column] = $relation->keyIsLocal()
            ? [$entity, $this->naming->column($edge->name) . '_id']
            : [$target, $this->naming->foreignKeyColumn($entity, $edge)];

        $table = $this->naming->table($owner);
        $schemaForTable = $tables[$table] ?? null;

        if (null === $schemaForTable) {
            return;
        }

        if (null !== $schemaForTable->column($column)) {
            throw new RuntimeException(sprintf(
                'Edge %s.%s needs column "%s" on %s, but a field already claims it.',
                $entity->name,
                $edge->name,
                $column,
                $table,
            ));
        }

        // Nullable regardless of the relation: the referencing row can exist before
        // the row it points at is attached, and a unit of work relies on that.
        $withColumn = $schemaForTable->withColumn(
            new Column($column, 'BIGINT UNSIGNED', nullable: true),
        );

        $index = $relation->keyIsLocal() && true === $edge->inverse?->unique
            ? new Index($this->naming->uniqueName($table, $column), [$column], unique: true)
            : new Index($this->naming->indexName($table, $column), [$column]);

        $tables[$table] = $withColumn->withIndex($index);
    }

    private function joinTable(
        EntityDefinition $left,
        EntityDefinition $right,
        EdgeDefinition $edge,
    ): TableSchema {
        $name = $this->naming->joinTable($left, $edge);
        $leftColumn = $this->naming->joinColumn($left->name);
        $rightColumn = $this->naming->joinColumn($right->name);

        return new TableSchema(
            $name,
            [
                $leftColumn => new Column($leftColumn, 'BIGINT UNSIGNED'),
                $rightColumn => new Column($rightColumn, 'BIGINT UNSIGNED'),
            ],
            [
                // The pair is the identity of a link, so uniqueness is the primary key.
                $this->naming->uniqueName($name, 'pair') => new Index(
                    $this->naming->uniqueName($name, 'pair'),
                    [$leftColumn, $rightColumn],
                    unique: true,
                ),
                $this->naming->indexName($name, $rightColumn) => new Index(
                    $this->naming->indexName($name, $rightColumn),
                    [$rightColumn],
                ),
            ],
            primaryKey: '',
        );
    }

    private function columnType(Schema $schema, FieldDefinition $field): string
    {
        $primitive = $field->type->primitive;

        if (null === $primitive) {
            // A declared value type stores as its backing primitive; the schema
            // compiler has already proved the type exists.
            return 'LONGTEXT';
        }

        return match ($primitive) {
            Primitive::String => sprintf('VARCHAR(%d)', $field->maxLength ?? self::DEFAULT_STRING_LENGTH),
            Primitive::Text, Primitive::Json => 'LONGTEXT',
            Primitive::Int => 'BIGINT',
            Primitive::Float => 'DOUBLE',
            Primitive::Bool => 'TINYINT(1)',
            Primitive::Datetime => 'DATETIME',
            Primitive::Id => 'BIGINT UNSIGNED',
            Primitive::Enum => $this->enumColumnType($schema, $field),
        };
    }

    /**
     * Sized to the longest member rather than a fixed width.
     *
     * Fully determined by the spec, and adding a longer member is a genuine schema
     * change that should surface as a migration rather than being absorbed silently.
     */
    private function enumColumnType(Schema $schema, FieldDefinition $field): string
    {
        $enum = $field->enum;

        if (null === $enum) {
            return 'VARCHAR(64)';
        }

        $values = $enum->inlineValues
            ?? $schema->type((string) $enum->declaredType)->values
            ?? [];

        if ([] === $values) {
            return 'VARCHAR(64)';
        }

        $longest = max(array_map(strlen(...), $values));

        return sprintf('VARCHAR(%d)', max($longest, 1));
    }
}
