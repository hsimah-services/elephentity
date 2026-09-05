<?php

declare(strict_types=1);

namespace PheFr\WordPress;

use PheFr\Runtime\Capability\Capabilities;
use PheFr\Runtime\Capability\Capability;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Storage\Criteria;
use PheFr\Runtime\Storage\Page;
use PheFr\Runtime\Storage\Record;
use PheFr\Runtime\Storage\StorageAdaptor;
use PheFr\Runtime\Storage\Write\Delete;
use PheFr\Runtime\Storage\Write\Insert;
use PheFr\Runtime\Storage\Write\Update;
use PheFr\Runtime\Storage\Write\WriteBatch;
use PheFr\Runtime\Storage\Write\WriteResult;
use PheFr\WordPress\Database\Database;
use PheFr\WordPress\Sql\Naming;
use PheFr\WordPress\Sql\QueryCompiler;
use PheFr\WordPress\Sql\TableSchema;
use RuntimeException;
use Throwable;

/**
 * Stores entities in custom MariaDB tables inside a WordPress installation.
 *
 * Thin by design: the interesting decisions — how the spec becomes a schema, how a
 * Criteria becomes SQL — live in pure classes that can be tested without a database.
 * What remains here is dispatch and the transaction boundary.
 */
final readonly class WordPressAdaptor implements StorageAdaptor
{
    /**
     * @param array<string, TableSchema> $tables Keyed by entity name.
     */
    public function __construct(
        private Database $database,
        private array $tables,
        private QueryCompiler $compiler = new QueryCompiler(),
        private Naming $naming = new Naming(),
    ) {
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            'wordpress',
            Capability::Transactions,
            Capability::RowLocking,
            Capability::FullTextSearch,
            // No foreign keys: dbDelta cannot express them and we enforce integrity in
            // the framework instead, where the errors are better and actions still run.
        );
    }

    public function get(string $entity, EntityId $id): ?Record
    {
        $table = $this->table($entity);

        $rows = $this->database->select(
            sprintf('SELECT * FROM `%s` WHERE `id` = %%d LIMIT 1', $table->name),
            [$id->raw()],
        );

        return [] === $rows ? null : $this->record($entity, $rows[0]);
    }

    public function getMany(string $entity, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $table = $this->table($entity);
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $rows = $this->database->select(
            sprintf('SELECT * FROM `%s` WHERE `id` IN (%s)', $table->name, $placeholders),
            array_map(static fn (EntityId $id): int|string => $id->raw(), $ids),
        );

        return array_map(fn (array $row): Record => $this->record($entity, $row), $rows);
    }

    public function query(Criteria $criteria): Page
    {
        $table = $this->table($criteria->entity);
        $compiled = $this->compiler->select($table, $criteria);

        $rows = $this->database->select($compiled->sql, $compiled->bindings);

        return new Page(array_map(
            fn (array $row): Record => $this->record($criteria->entity, $row),
            $rows,
        ));
    }

    public function count(Criteria $criteria): int
    {
        $compiled = $this->compiler->count($this->table($criteria->entity), $criteria);

        return (int) $this->database->scalar($compiled->sql, $compiled->bindings);
    }

    public function write(WriteBatch $batch): WriteResult
    {
        $result = new WriteResult();

        foreach ($batch->operations as $operation) {
            $table = $this->table($operation->entity());

            match (true) {
                $operation instanceof Insert => $result->assign(
                    $operation->pendingId(),
                    EntityId::of($this->database->insert($table->name, $operation->values)),
                ),
                $operation instanceof Update => $this->update($table, $operation),
                $operation instanceof Delete => $this->delete($table, $operation),
                default => throw new RuntimeException(sprintf(
                    'Unsupported write operation %s.',
                    $operation::class,
                )),
            };
        }

        return $result;
    }

    public function transaction(callable $work): mixed
    {
        $this->database->beginTransaction();

        try {
            $value = $work();
        } catch (Throwable $exception) {
            $this->database->rollBack();

            throw $exception;
        }

        $this->database->commit();

        return $value;
    }

    private function update(TableSchema $table, Update $operation): void
    {
        if ([] === $operation->values) {
            return;
        }

        $assignments = [];
        $bindings = [];

        foreach ($operation->values as $field => $value) {
            $assignments[] = sprintf('`%s` = %%s', $this->naming->column($field));
            $bindings[] = $value;
        }

        $target = $operation->target();
        $bindings[] = $target instanceof EntityId ? $target->raw() : (string) $target;

        $this->database->execute(
            sprintf(
                'UPDATE `%s` SET %s WHERE `id` = %%d',
                $table->name,
                implode(', ', $assignments),
            ),
            $bindings,
        );
    }

    private function delete(TableSchema $table, Delete $operation): void
    {
        $target = $operation->target();

        $this->database->execute(
            sprintf('DELETE FROM `%s` WHERE `id` = %%d', $table->name),
            [$target instanceof EntityId ? $target->raw() : (string) $target],
        );
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private function record(string $entity, array $row): Record
    {
        $id = $row['id'] ?? null;

        if (!is_int($id) && !is_string($id)) {
            throw new RuntimeException(sprintf('A %s row came back without an id.', $entity));
        }

        unset($row['id']);

        return new Record($entity, EntityId::of($id), $row);
    }

    private function table(string $entity): TableSchema
    {
        return $this->tables[$entity] ?? throw new RuntimeException(sprintf(
            'No table is mapped for entity "%s".',
            $entity,
        ));
    }
}
