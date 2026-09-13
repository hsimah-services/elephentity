<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\StorageAdaptor;

/**
 * Builds a lazy query, so a hand-written finder does not have to.
 *
 * A generated `ItemFinder` delegates to an `ItemSearchQuery` the application writes,
 * and that class has to return an `EntityQuery<Item>`. Constructing one directly means
 * assembling a storage adaptor, the right hydrator and an edge loader — four
 * collaborators to answer "items whose name contains this", which is enough friction
 * to make people reach past the port and write SQL.
 *
 * Taking the hydrator rather than an entity name is what keeps the result exactly
 * typed: an `ItemHydrator` is a `Hydrator<Item>`, so what comes back is an
 * `EntityQuery<Item>` and the contract is satisfied without a cast.
 */
final readonly class Queries
{
    public function __construct(
        private StorageAdaptor $storage,
        private EdgeLoader $edges,
        private ReadGate $gate,
    ) {
    }

    /**
     * @template T of object
     *
     * @param Hydrator<T> $hydrator
     *
     * @return EntityQuery<T>
     */
    public function of(Hydrator $hydrator, Criteria $criteria): EntityQuery
    {
        return new LazyEntityQuery($this->storage, $hydrator, $this->edges, $criteria, $this->gate);
    }
}
