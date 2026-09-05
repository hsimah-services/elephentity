<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

use PheFr\Runtime\Capability\Capabilities;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Storage\Write\WriteBatch;
use PheFr\Runtime\Storage\Write\WriteResult;

/**
 * The port every storage backend implements.
 *
 * Deliberately narrow, and deliberately defined before a second implementation exists.
 * WordPress concepts — integer post ids, postmeta as untyped key-value, taxonomies
 * standing in for edges, WP_Query semantics — leak quietly, and by the time they are
 * load-bearing a second adaptor is no longer possible. An architecture rule enforces
 * that no WP symbol appears outside the wordpress package.
 *
 * Everything here speaks in primitives and entity names. Domain types, processors and
 * verification live above; SQL, meta tables and hooks live below.
 */
interface StorageAdaptor
{
    /**
     * What this backend can do. Callers requiring more should say so explicitly rather
     * than discovering the gap at runtime.
     */
    public function capabilities(): Capabilities;

    public function get(string $entity, EntityId $id): ?Record;

    /**
     * Fetch many rows in one round trip.
     *
     * This is the batching primitive the edge loader is built on: one query for the
     * comments of fifty posts rather than fifty queries.
     *
     * @param list<EntityId> $ids
     *
     * @return list<Record> In no guaranteed order; missing ids are simply absent.
     */
    public function getMany(string $entity, array $ids): array;

    /**
     * @return Page<Record>
     */
    public function query(Criteria $criteria): Page;

    /**
     * Count matches without materialising them.
     *
     * Separate from query() because a count must not pay for hydration — that is the
     * point of the lazy edge query offering count() at all.
     */
    public function count(Criteria $criteria): int;

    /**
     * Apply an ordered batch of writes.
     *
     * Whether this is atomic depends on Capability::Transactions; callers that need
     * all-or-nothing must require it.
     */
    public function write(WriteBatch $batch): WriteResult;

    /**
     * Run $work inside a transaction, committing on return and rolling back on throw.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed;
}
