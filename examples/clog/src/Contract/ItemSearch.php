<?php

declare(strict_types=1);

namespace Clog\Contract;

use Clog\Entity\Item\Contract\ItemSearchQuery;
use Clog\Entity\Item\Item;
use Clog\Entity\Item\ItemHydrator;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Query\Queries;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Direction;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Order;

/**
 * `Item.search` from the spec, implemented.
 *
 * The generator emitted `ItemFinder::search(string $term)` and the interface above it;
 * this is the part no generator could invent, and it is the only part written by hand.
 *
 * The lazy query comes from `Queries` rather than being assembled here, and taking the
 * hydrator keeps the return type exact: an `ItemHydrator` is a `Hydrator<Item>`, so
 * what comes back is an `EntityQuery<Item>` and the contract is met without a cast.
 */
final readonly class ItemSearch implements ItemSearchQuery
{
    public function __construct(
        private Queries $queries,
        private ItemHydrator $hydrator,
    ) {
    }

    /**
     * @return EntityQuery<Item>
     */
    public function find(string $term): EntityQuery
    {
        // Filters on a Criteria are conjunctive, so "name or barcode" is not one query
        // through the port. Scanning produces digits and typing produces words, so the
        // shape of the term is the honest way to pick — and it keeps both paths on an
        // index rather than making one of them a table scan.
        $criteria = (new Criteria('Item'))
            ->where(1 === preg_match('/^\d+$/', $term)
                ? new Filter('barcode', Comparison::Equals, $term)
                : new Filter('name', Comparison::Contains, $term))
            ->orderBy(new Order('name', Direction::Ascending));

        return $this->queries->of($this->hydrator, $criteria);
    }
}
