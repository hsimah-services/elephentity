<?php

declare(strict_types=1);

namespace Eleph\WordPress\Account;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\WordPress\Sql\FieldMap;
use RuntimeException;

/**
 * Reads and writes an account-backed entity's own rows — `wp_users` core columns and
 * `wp_usermeta`, never a table of the framework's own.
 *
 * The pure counterpart to `WordPressAdaptor`'s SQL half, tested the same way
 * `TaxonomyStorage` is: given a `Users` port, this decides *what* to ask for.
 *
 * Existence is `wp_users` having the row, nothing else. A row this framework has
 * never written to still loads — every declared field simply reads back null (or,
 * for the one field the unit of work would have stamped `Managed::Modified`, the
 * registration date) — because the account existed the moment WordPress registered
 * it, not the moment this framework first touched it.
 */
final readonly class AccountStorage
{
    public function __construct(private Users $users = new WordPressUsers())
    {
    }

    public function get(string $entity, AccountFields $fields, FieldMap $map, EntityId $id): ?Record
    {
        $wpUserId = $this->wpUserId($id);

        if (!$this->users->exists($wpUserId)) {
            return null;
        }

        return $this->record($entity, $fields, $map, $wpUserId, $this->users->meta($wpUserId, $this->metaKeys($fields, $map)));
    }

    /**
     * @param list<EntityId> $ids
     *
     * @return list<Record>
     */
    public function getMany(string $entity, AccountFields $fields, FieldMap $map, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $keys = $this->metaKeys($fields, $map);
        $keysByUser = [];

        foreach ($ids as $id) {
            $keysByUser[$this->wpUserId($id)] = $keys;
        }

        $meta = $this->users->metaForMany($keysByUser);
        $records = [];

        foreach ($ids as $id) {
            $wpUserId = $this->wpUserId($id);

            if ($this->users->exists($wpUserId)) {
                $records[] = $this->record($entity, $fields, $map, $wpUserId, $meta[$wpUserId] ?? []);
            }
        }

        return $records;
    }

    /**
     * @return Page<Record>
     */
    public function query(string $entity, AccountFields $fields, FieldMap $map, Criteria $criteria): Page
    {
        $this->assertPlainListing($criteria);

        $offset = Offset::fromCursor($criteria->after)->value;
        $ids = $this->users->list(null === $criteria->limit ? null : $criteria->limit + 1, $offset);

        $keys = $this->metaKeys($fields, $map);
        $keysByUser = [];

        foreach ($ids as $wpUserId) {
            $keysByUser[$wpUserId] = $keys;
        }

        $meta = $this->users->metaForMany($keysByUser);
        $records = [];

        foreach ($ids as $wpUserId) {
            $records[] = $this->record($entity, $fields, $map, $wpUserId, $meta[$wpUserId] ?? []);
        }

        if (null === $criteria->limit) {
            return new Page($records);
        }

        $hasMore = count($records) > $criteria->limit;

        return new Page(
            array_slice($records, 0, $criteria->limit),
            $hasMore ? (new Offset($offset + $criteria->limit))->toCursor() : null,
        );
    }

    public function count(Criteria $criteria): int
    {
        $this->assertPlainListing($criteria);

        return $this->users->count();
    }

    public function insert(): never
    {
        throw new RuntimeException(
            'An account-backed entity cannot be created here: its rows are WordPress '
                . 'accounts, which already exist the moment WordPress registers them.',
        );
    }

    public function update(AccountFields $fields, FieldMap $map, Update $operation): void
    {
        $target = $operation->target();

        if (!$target instanceof EntityId) {
            throw new RuntimeException('An account update needs a persisted id.');
        }

        $wpUserId = $this->wpUserId($target);

        foreach ($operation->values as $field => $value) {
            if ($field === $fields->createdField) {
                // Stamped nowhere: Managed::Created never arrives outside an insert,
                // and this class refuses those, but a value here would be silently
                // wrong to write rather than merely unreachable.
                continue;
            }

            $this->users->updateMeta($wpUserId, $map->column($fields->entity, $field), $value);
        }
    }

    public function delete(): never
    {
        throw new RuntimeException(
            'An account-backed entity cannot be deleted here: deleting a WordPress '
                . 'account is a WordPress-level decision, not a unit-of-work one.',
        );
    }

    /**
     * @param array<string, scalar|null> $meta Meta key => value, only keys that exist.
     */
    private function record(string $entity, AccountFields $fields, FieldMap $map, int $wpUserId, array $meta): Record
    {
        $values = [];

        foreach ($meta as $key => $value) {
            $values[$map->field($fields->entity, $key)] = $value;
        }

        if (null !== $fields->createdField) {
            $values[$fields->createdField] = $this->users->registeredAt($wpUserId);
        }

        if (null !== $fields->modifiedField && !array_key_exists($fields->modifiedField, $values)) {
            // Written once, on the first update this framework ever makes; until then,
            // "last modified" and "registered" are the same honest answer.
            $values[$fields->modifiedField] = $this->users->registeredAt($wpUserId);
        }

        return new Record($entity, EntityId::of($wpUserId), $values);
    }

    /**
     * @return list<string>
     */
    private function metaKeys(AccountFields $fields, FieldMap $map): array
    {
        $keys = [];

        foreach ($map->fieldsOf($fields->entity) as $field) {
            if ($field === $fields->createdField) {
                continue;
            }

            $keys[] = $map->column($fields->entity, $field);
        }

        return $keys;
    }

    private function assertPlainListing(Criteria $criteria): void
    {
        if ([] !== $criteria->filters || [] !== $criteria->order || [] !== $criteria->links) {
            throw new RuntimeException(
                'An account-backed entity supports listing and paging only; field '
                    . 'filters, ordering and "linked to" queries are not available.',
            );
        }
    }

    private function wpUserId(Identifier $identifier): int
    {
        $raw = $identifier instanceof EntityId ? $identifier->raw() : (string) $identifier;

        return is_int($raw) ? $raw : (int) $raw;
    }
}
