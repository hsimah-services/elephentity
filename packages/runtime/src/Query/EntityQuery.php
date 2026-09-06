<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Page;

/**
 * A lazy, batchable handle on a set of entities.
 *
 * Returned by every to-many edge accessor and every generated finder, so traversal and
 * custom queries behave identically. Not an array, for three reasons: an unbounded
 * load becomes a deliberate all() rather than the default; GraphQL connections map
 * onto page() directly instead of a resolver slicing an already-hydrated array; and
 * the loader can batch across a result set, which is the N+1 defence and is very hard
 * to add once code everywhere assumes an array.
 *
 * @template T of object
 */
interface EntityQuery
{
    /**
     * How many entities match, without hydrating any of them.
     */
    public function count(): int;

    /**
     * @return Page<T>
     */
    public function page(int $limit, ?Cursor $after = null): Page;

    /**
     * Hydrate everything that matches.
     *
     * Explicit because it is unbounded. Reach for page() unless the set is known small.
     *
     * @return list<T>
     */
    public function all(): array;

    /**
     * @return T|null
     */
    public function first(): ?object;

    public function exists(): bool;
}
