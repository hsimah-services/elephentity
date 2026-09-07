<?php

declare(strict_types=1);

namespace Eleph\WordPress\Sql;

/**
 * Every SQL identifier this adaptor derives, in one place.
 *
 * Strings in, strings out. It once took `EntityDefinition` and `EdgeDefinition`, which
 * made a class the query compiler loads on every request carry signatures naming a
 * build-time package — so the caller that holds an entity now reads the name off it and
 * passes that. The rules are the same; what they need to know is less.
 *
 * Storage naming is inferred, never declared — if the generator can work it out, a
 * human choosing it is a chance for two entities to disagree.
 */
final readonly class Naming
{
    public function __construct(private string $prefix = '')
    {
    }

    public function table(string $table): string
    {
        return $this->prefix . $table;
    }

    /**
     * camelCase in the spec, snake_case in the database — the convention on each side.
     */
    public function column(string $field): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $field);

        return strtolower($snake ?? $field);
    }

    /**
     * The foreign key column an edge puts on the far side.
     *
     * Named from the reverse accessor, so Post.comments (inverse: post) puts post_id
     * on the comment table — the same name a reader would guess.
     */
    public function foreignKeyColumn(string $reverseAccessor): string
    {
        return $this->column($reverseAccessor) . '_id';
    }

    /**
     * The join table a many-to-many edge derives.
     */
    public function joinTable(string $table, string $edge): string
    {
        return $this->table($table) . '_' . $this->column($edge);
    }

    public function joinColumn(string $entityName): string
    {
        return $this->column(lcfirst($entityName)) . '_id';
    }

    public function indexName(string $table, string $column): string
    {
        // MySQL caps identifiers at 64 characters.
        return substr(sprintf('%s_%s_idx', $table, $column), 0, 64);
    }

    public function uniqueName(string $table, string $column): string
    {
        return substr(sprintf('%s_%s_uniq', $table, $column), 0, 64);
    }
}
