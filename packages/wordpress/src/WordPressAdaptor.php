<?php

declare(strict_types=1);

namespace Eleph\WordPress;

use Eleph\Runtime\Capability\Capabilities;
use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Storage\Write\WriteResult;
use Eleph\WordPress\Account\AccountFields;
use Eleph\WordPress\Account\AccountStorage;
use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Sql\EdgePlacement;
use Eleph\WordPress\Sql\FieldMap;
use Eleph\WordPress\Sql\QueryCompiler;
use Eleph\WordPress\Sql\TableSchema;
use Eleph\WordPress\Taxonomy\TaxonomyPlacement;
use Eleph\WordPress\Taxonomy\TaxonomyStorage;
use RuntimeException;
use Throwable;

/**
 * Stores entities in custom MariaDB tables inside a WordPress installation — or, for a
 * taxonomy-backed entity, as terms, or for an account-backed entity, as `wp_users` and
 * `wp_usermeta`.
 *
 * Thin by design: the interesting decisions — how the spec becomes a schema, how a
 * Criteria becomes SQL, how one becomes a term or an account instead — live in pure
 * classes that can be tested without a database. What remains here is dispatch and the
 * transaction boundary. Dispatch is by entity name for an entity's own rows, and by
 * "Entity.edge" for an edge that turned out to be a term relationship rather than a
 * column — an edge pointing at an account-backed entity needs no such dispatch, since
 * it is stored as an ordinary column or join table like any other.
 */
final readonly class WordPressAdaptor implements StorageAdaptor
{
    /**
     * @param array<string, TableSchema>       $tables            Keyed by entity name.
     * @param array<string, EdgePlacement>     $placements        Keyed by "Entity.edge".
     * @param array<string, string>            $taxonomies        Entity name => taxonomy slug.
     * @param array<string, TaxonomyPlacement> $taxonomyPlacements Keyed by "Entity.edge".
     * @param array<string, AccountFields>      $accounts Keyed by entity name.
     */
    public function __construct(
        private Database $database,
        private array $tables,
        private FieldMap $fields,
        private array $placements = [],
        private QueryCompiler $compiler = new QueryCompiler(),
        private array $taxonomies = [],
        private array $taxonomyPlacements = [],
        private TaxonomyStorage $taxonomy = new TaxonomyStorage(),
        private array $accounts = [],
        private AccountStorage $account = new AccountStorage(),
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
        $taxonomy = $this->taxonomies[$entity] ?? null;

        if (null !== $taxonomy) {
            return $this->taxonomy->get($entity, $taxonomy, $id);
        }

        $account = $this->accounts[$entity] ?? null;

        if (null !== $account) {
            return $this->account->get($entity, $account, $this->fields, $id);
        }

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

        $taxonomy = $this->taxonomies[$entity] ?? null;

        if (null !== $taxonomy) {
            return $this->taxonomy->getMany($entity, $taxonomy, $ids);
        }

        $account = $this->accounts[$entity] ?? null;

        if (null !== $account) {
            return $this->account->getMany($entity, $account, $this->fields, $ids);
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
        $taxonomy = $this->taxonomies[$criteria->entity] ?? null;

        if (null !== $taxonomy) {
            return $this->taxonomy->query($criteria->entity, $taxonomy, $criteria);
        }

        $account = $this->accounts[$criteria->entity] ?? null;

        if (null !== $account) {
            return $this->accountQuery($criteria->entity, $account, $criteria);
        }

        $table = $this->table($criteria->entity);
        $compiled = $this->compiler->select($table, $criteria);

        $rows = $this->database->select($compiled->sql, $compiled->bindings);

        $records = array_map(
            fn (array $row): Record => $this->record($criteria->entity, $row),
            $rows,
        );

        if (null === $criteria->limit) {
            return new Page($records);
        }

        // The compiler asked for one row more than the caller wanted. Its presence is
        // the answer to "is there another page", and it is dropped here so nothing
        // above ever sees it.
        $offset = Offset::fromCursor($criteria->after)->value;
        $hasMore = count($records) > $criteria->limit;

        return new Page(
            array_slice($records, 0, $criteria->limit),
            $hasMore ? (new Offset($offset + $criteria->limit))->toCursor() : null,
        );
    }

    public function count(Criteria $criteria): int
    {
        $taxonomy = $this->taxonomies[$criteria->entity] ?? null;

        if (null !== $taxonomy) {
            return $this->taxonomy->count($taxonomy, $criteria);
        }

        $account = $this->accounts[$criteria->entity] ?? null;

        if (null !== $account) {
            return $this->accountCount($criteria->entity, $account, $criteria);
        }

        $compiled = $this->compiler->count($this->table($criteria->entity), $criteria);

        return (int) $this->database->scalar($compiled->sql, $compiled->bindings);
    }

    /**
     * An account-backed entity has no table of its own to join into, so `AccountStorage`
     * can only ever offer a plain, unfiltered listing. That is a real limitation for a
     * root query — but a "linked to" criteria whose key already sits on the other
     * (ordinary) table is not a filter on the account at all: it is a to-one or
     * many-to-one edge pointing at the account, and answering it means reading the
     * declaring row's own key column, the same thing a SQL join would do if the account
     * had a table to join into.
     *
     * Anything else — a reversed link or one crossing a join table — still has no
     * column to read, and falls through to `AccountStorage`, which refuses it.
     *
     * @return Page<Record>
     */
    private function accountQuery(string $entity, AccountFields $account, Criteria $criteria): Page
    {
        $pairs = $this->linkedAccountIds($entity, $criteria);

        if (null === $pairs) {
            return $this->account->query($entity, $account, $this->fields, $criteria);
        }

        $targets = array_values(array_unique(array_column($pairs, 'target'), SORT_REGULAR));
        $records = $this->account->getMany(
            $entity,
            $account,
            $this->fields,
            array_map(static fn (int|string $id): EntityId => EntityId::of($id), $targets),
        );

        $byTarget = [];

        foreach ($records as $record) {
            $byTarget[(string) $record->id->raw()] = $record;
        }

        $projectParent = 1 === count($criteria->links) && $criteria->links[0]->needsParentColumn();
        $items = [];

        foreach ($pairs as $pair) {
            $record = $byTarget[(string) $pair['target']] ?? null;

            if (null === $record) {
                continue;
            }

            $items[] = $projectParent
                ? new Record($record->entity, $record->id, [...$record->values, EdgeFilter::PARENT_COLUMN => $pair['parent']])
                : $record;
        }

        return new Page($items);
    }

    private function accountCount(string $entity, AccountFields $account, Criteria $criteria): int
    {
        $pairs = $this->linkedAccountIds($entity, $criteria);

        if (null === $pairs) {
            return $this->account->count($criteria);
        }

        return count($this->accountQuery($entity, $account, $criteria)->items);
    }

    /**
     * Resolve a "linked to" criteria against an account-backed entity to the pairs of
     * (parent id, account id) it names, by reading the key straight off the declaring
     * entity's own table — or null when the criteria is not that one resolvable shape,
     * so the caller falls back to `AccountStorage`'s own, narrower, refusal.
     *
     * @return list<array{parent: int|string, target: int|string}>|null
     */
    private function linkedAccountIds(string $entity, Criteria $criteria): ?array
    {
        if ([] !== $criteria->filters || [] !== $criteria->order || 1 !== count($criteria->links)) {
            return null;
        }

        $link = $criteria->links[0];
        $placement = $this->placements[$link->entity . '.' . $link->edge] ?? null;

        if (null === $placement || $link->reversed || !$placement->keyIsLocal() || $placement->target !== $entity) {
            return null;
        }

        $parentIds = array_map($this->rawId(...), $link->from);
        $placeholders = implode(', ', array_fill(0, count($parentIds), '%d'));

        $rows = $this->database->select(
            sprintf(
                'SELECT `id`, `%s` FROM `%s` WHERE `id` IN (%s)',
                $placement->localColumn,
                $placement->table,
                $placeholders,
            ),
            $parentIds,
        );

        $pairs = [];

        foreach ($rows as $row) {
            $parent = $row['id'] ?? null;
            $target = $row[$placement->localColumn] ?? null;

            if ((!is_int($parent) && !is_string($parent)) || (!is_int($target) && !is_string($target))) {
                continue;
            }

            $pairs[] = ['parent' => $parent, 'target' => $target];
        }

        return $pairs;
    }

    public function write(WriteBatch $batch): WriteResult
    {
        $result = new WriteResult();

        foreach ($batch->operations as $operation) {
            $taxonomy = $this->taxonomies[$operation->entity()] ?? null;

            if (null !== $taxonomy && $operation instanceof Insert) {
                $result->assign($operation->pendingId(), $this->taxonomy->insert($taxonomy, $operation));

                continue;
            }

            if (null !== $taxonomy && $operation instanceof Update) {
                $this->taxonomy->update($taxonomy, $operation);

                continue;
            }

            if (null !== $taxonomy && $operation instanceof Delete) {
                $this->taxonomy->delete($taxonomy, $operation);

                continue;
            }

            if ($operation instanceof Link && null !== ($placement = $this->taxonomyPlacements[$this->edgeKey($operation)] ?? null)) {
                $this->taxonomy->link($placement->taxonomy, $operation);

                continue;
            }

            if ($operation instanceof Unlink && null !== ($placement = $this->taxonomyPlacements[$this->edgeKey($operation)] ?? null)) {
                $this->taxonomy->unlink($placement->taxonomy, $operation);

                continue;
            }

            $account = $this->accounts[$operation->entity()] ?? null;

            if (null !== $account && $operation instanceof Insert) {
                $this->account->insert();
            }

            if (null !== $account && $operation instanceof Update) {
                $this->account->update($account, $this->fields, $operation);

                continue;
            }

            if (null !== $account && $operation instanceof Delete) {
                $this->account->delete();
            }

            $table = $this->table($operation->entity());

            match (true) {
                $operation instanceof Insert => $result->assign(
                    $operation->pendingId(),
                    EntityId::of($this->database->insert(
                        $table->name,
                        $this->fields->toColumns($operation->entity(), $operation->values),
                    )),
                ),
                $operation instanceof Update => $this->update($table, $operation),
                $operation instanceof Delete => $this->delete($table, $operation),
                $operation instanceof Link => $this->link($operation),
                $operation instanceof Unlink => $this->unlink($operation),
                default => throw new RuntimeException(sprintf(
                    'Unsupported write operation %s.',
                    $operation::class,
                )),
            };
        }

        return $result;
    }

    /**
     * @param Link|Unlink $operation
     */
    private function edgeKey(Link|Unlink $operation): string
    {
        return $operation->entity() . '.' . $operation->edge;
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

    /**
     * Attach one row to another.
     *
     * How that happens depends on where the edge lives, which is exactly why Link says
     * nothing about columns: a foreign key on either side and a join table are three
     * different statements for the same intent.
     */
    private function link(Link $operation): void
    {
        $placement = $this->placement($operation->entity(), $operation->edge);
        $from = $this->rawId($operation->from);
        $to = $this->rawId($operation->to);

        if ($placement->usesJoinTable()) {
            // INSERT IGNORE, because linking twice is not an error — the unique key on
            // the pair already says a link exists at most once.
            $this->database->execute(
                sprintf(
                    'INSERT IGNORE INTO `%s` (`%s`, `%s`) VALUES (%%d, %%d)',
                    $placement->table,
                    $placement->localColumn,
                    (string) $placement->targetColumn,
                ),
                [$from, $to],
            );

            return;
        }

        // The key column lives on one side or the other; whichever it is, the row
        // carrying it is updated to point at the other.
        [$row, $value] = $placement->keyIsLocal() ? [$from, $to] : [$to, $from];

        $this->database->execute(
            sprintf(
                'UPDATE `%s` SET `%s` = %%d WHERE `id` = %%d',
                $placement->table,
                $placement->localColumn,
            ),
            [$value, $row],
        );
    }

    /**
     * Detach one row from another, or clear the edge entirely when no target is named.
     */
    private function unlink(Unlink $operation): void
    {
        $placement = $this->placement($operation->entity(), $operation->edge);
        $from = $this->rawId($operation->from);
        $to = null === $operation->to ? null : $this->rawId($operation->to);

        if ($placement->usesJoinTable()) {
            $sql = sprintf(
                'DELETE FROM `%s` WHERE `%s` = %%d',
                $placement->table,
                $placement->localColumn,
            );

            $bindings = [$from];

            if (null !== $to) {
                $sql .= sprintf(' AND `%s` = %%d', (string) $placement->targetColumn);
                $bindings[] = $to;
            }

            $this->database->execute($sql, $bindings);

            return;
        }

        if ($placement->keyIsLocal()) {
            $this->database->execute(
                sprintf('UPDATE `%s` SET `%s` = NULL WHERE `id` = %%d', $placement->table, $placement->localColumn),
                [$from],
            );

            return;
        }

        $sql = sprintf(
            'UPDATE `%s` SET `%s` = NULL WHERE `%s` = %%d',
            $placement->table,
            $placement->localColumn,
            $placement->localColumn,
        );

        $bindings = [$from];

        if (null !== $to) {
            $sql .= ' AND `id` = %d';
            $bindings[] = $to;
        }

        $this->database->execute($sql, $bindings);
    }

    private function placement(string $entity, string $edge): EdgePlacement
    {
        return $this->placements[$entity . '.' . $edge] ?? throw new RuntimeException(sprintf(
            'Edge %s.%s is not mapped.',
            $entity,
            $edge,
        ));
    }

    private function rawId(Identifier $identifier): int|string
    {
        return $identifier instanceof EntityId ? $identifier->raw() : (string) $identifier;
    }

    private function update(TableSchema $table, Update $operation): void
    {
        if ([] === $operation->values) {
            return;
        }

        $assignments = [];
        $bindings = [];

        foreach ($operation->values as $field => $value) {
            $assignments[] = sprintf('`%s` = %%s', $this->fields->column($operation->entity(), $field));
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

        // Columns become fields here, so nothing above the adaptor ever sees a
        // snake_case name or has to know how this backend spells things.
        return new Record($entity, EntityId::of($id), $this->fields->toFields($entity, $row));
    }

    private function table(string $entity): TableSchema
    {
        return $this->tables[$entity] ?? throw new RuntimeException(sprintf(
            'No table is mapped for entity "%s".',
            $entity,
        ));
    }
}
