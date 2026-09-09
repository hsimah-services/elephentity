<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

use Eleph\Runtime\Storage\RelationKind;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\Schema;
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

    private EdgePlanner $planner;

    public function __construct(
        private Naming $naming = new Naming(),
        ?EdgePlanner $planner = null,
    ) {
        // The planner must name tables exactly as this builder does; defaulting it to
        // its own Naming would silently give the two different prefixes.
        $this->planner = $planner ?? new EdgePlanner($naming);
    }

    /**
     * @return array<string, TableSchema> Keyed by table name.
     */
    public function build(Schema $schema): array
    {
        $tables = [];

        foreach ($schema->entities as $entity) {
            $tables[$this->naming->table($entity->storage->table)] = $this->entityTable($schema, $entity);
        }

        // Edges are resolved second: a one-to-many edge on Post adds a column to the
        // comment table, which must already exist. Placement comes from the planner so
        // that the query compiler cannot disagree about where a link lives.
        foreach ($this->planner->plan($schema) as $placement) {
            $this->applyEdge($tables, $placement);
        }

        ksort($tables);

        return $tables;
    }

    private function entityTable(Schema $schema, EntityDefinition $entity): TableSchema
    {
        $table = $this->naming->table($entity->storage->table);

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
    private function applyEdge(array &$tables, EdgePlacement $placement): void
    {
        if ($placement->usesJoinTable()) {
            $tables[$placement->table] = $this->joinTable($placement);

            return;
        }

        $table = $tables[$placement->table] ?? null;

        if (null === $table) {
            return;
        }

        if (null !== $table->column($placement->localColumn)) {
            throw new RuntimeException(sprintf(
                'Edge %s.%s needs column "%s" on %s, but a field already claims it.',
                $placement->entity,
                $placement->edge,
                $placement->localColumn,
                $placement->table,
            ));
        }

        // Nullable regardless of the relation: the referencing row can exist before
        // the row it points at is attached, and a unit of work relies on that.
        $withColumn = $table->withColumn(
            new Column($placement->localColumn, 'BIGINT UNSIGNED', nullable: true),
        );

        $index = RelationKind::OneToOne === $placement->relation
            ? new Index(
                $this->naming->uniqueName($placement->table, $placement->localColumn),
                [$placement->localColumn],
                unique: true,
            )
            : new Index(
                $this->naming->indexName($placement->table, $placement->localColumn),
                [$placement->localColumn],
            );

        $tables[$placement->table] = $withColumn->withIndex($index);
    }

    private function joinTable(EdgePlacement $placement): TableSchema
    {
        $name = $placement->table;
        $left = $placement->localColumn;
        $right = (string) $placement->targetColumn;

        return new TableSchema(
            $name,
            [
                $left => new Column($left, 'BIGINT UNSIGNED'),
                $right => new Column($right, 'BIGINT UNSIGNED'),
            ],
            [
                // The pair is the identity of a link, so uniqueness is the key.
                $this->naming->uniqueName($name, 'pair') => new Index(
                    $this->naming->uniqueName($name, 'pair'),
                    [$left, $right],
                    unique: true,
                ),
                $this->naming->indexName($name, $right) => new Index(
                    $this->naming->indexName($name, $right),
                    [$right],
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
            return $this->declaredTypeColumnType($schema, $field);
        }

        return $this->primitiveColumnType($primitive, $schema, $field);
    }

    private function declaredTypeColumnType(Schema $schema, FieldDefinition $field): string
    {
        $declared = $schema->type((string) $field->type->declaredType);

        if (null === $declared) {
            throw new RuntimeException(sprintf(
                'Field %s references declared type "%s", which the schema compiler '
                . 'should already have proved exists.',
                $field->name,
                (string) $field->type->declaredType,
            ));
        }

        if ($declared->isEnum()) {
            return $this->sizeToLongest($declared->values ?? []);
        }

        return $this->primitiveColumnType($declared->primitive, $schema, $field);
    }

    private function primitiveColumnType(Primitive $primitive, Schema $schema, FieldDefinition $field): string
    {
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

        return $this->sizeToLongest($values);
    }

    /**
     * @param list<string> $values
     */
    private function sizeToLongest(array $values): string
    {
        if ([] === $values) {
            return 'VARCHAR(64)';
        }

        $longest = max(array_map(strlen(...), $values));

        return sprintf('VARCHAR(%d)', max($longest, 1));
    }
}
