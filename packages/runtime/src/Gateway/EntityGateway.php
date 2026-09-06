<?php

declare(strict_types=1);

namespace Eleph\Runtime\Gateway;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Query\EntityQuery;

/**
 * Entities addressed by name rather than by type.
 *
 * Everything else in the framework is exactly typed, and deliberately so. A protocol
 * layer cannot be: a GraphQL resolver is handed the string "Item" and an array of
 * arguments, and has no compile-time way to reach `ItemFinder`. So it needs a facade
 * that takes names, and this is it.
 *
 * That makes it the one place where the type safety is given up on purpose. It is kept
 * as small as the protocol layers actually need, and everything behind it — hydration,
 * verification, the unit of work — stays typed.
 *
 * Every write commits. A protocol request is a transaction boundary; batching across
 * requests would mean holding a unit of work open across them.
 */
interface EntityGateway
{
    public function find(string $entity, EntityId $id): ?object;

    /**
     * @return EntityQuery<object>
     */
    public function all(string $entity): EntityQuery;

    /**
     * Run a query the spec declared, by name.
     *
     * @param array<array-key, mixed> $args
     *
     * @return EntityQuery<object>
     */
    public function runQuery(string $entity, string $query, array $args): EntityQuery;

    /**
     * @param array<string, mixed> $input Field name to raw value; coercion is the implementation's job.
     */
    public function create(string $entity, array $input): EntityId;

    /**
     * @param array<string, mixed> $input Only the fields to change.
     */
    public function update(string $entity, EntityId $id, array $input): void;

    public function delete(string $entity, EntityId $id): void;

    /**
     * @param array<array-key, mixed> $args
     */
    public function runAction(string $entity, string $action, EntityId $id, array $args): void;
}
