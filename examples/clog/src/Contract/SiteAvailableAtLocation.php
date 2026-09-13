<?php

declare(strict_types=1);

namespace Clog\Contract;

use Clog\Entity\Inventory\Contract\InventorySiteAvailabilityTrigger;
use Clog\Entity\Inventory\InventoryMutationContext;
use Clog\Entity\Site\Site;
use Clog\Entity\Site\SiteHydrator;
use DomainException;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Query\Queries;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;

/**
 * `Inventory.siteAvailability` from the spec, implemented.
 *
 * The invariant spans two edges of the row being written and one edge of a different
 * entity entirely — "this site" against "this location's sites" — which is exactly
 * what a field verifier cannot reach: `MutationContext` carries the whole pending row,
 * not the rows it points at. A trigger can query, so this loads `Location.sites` for
 * the pending location and checks the pending site against it, the same way
 * `wp_term_relationships` would if a human curator were doing it by hand.
 *
 * `Location.sites` and `Inventory.site` share one taxonomy, so "available at" and "is
 * at" never drift into two vocabularies that happen to look alike.
 */
final readonly class SiteAvailableAtLocation implements InventorySiteAvailabilityTrigger
{
    public function __construct(
        private Queries $queries,
        private SiteHydrator $hydrator,
    ) {
    }

    public function handle(InventoryMutationContext $context): void
    {
        $location = $context->pendingLocation();
        $sites = $context->pendingSite();

        if (null === $location || [] === $sites) {
            // Nothing to check yet: Inventory.location is not required by the spec,
            // and an entry naming no site makes no claim to validate.
            return;
        }

        $criteria = Criteria::for('Site')->linkedTo(EdgeFilter::along('Location', 'sites', $location));

        /** @var list<Site> $allowed */
        $allowed = $this->queries->of($this->hydrator, $criteria)->all();

        foreach ($sites as $site) {
            if (!$this->isAllowed($site, $allowed)) {
                throw new DomainException(sprintf(
                    'Site %s is not one of this entry\'s location\'s available sites — check Location.sites.',
                    $site,
                ));
            }
        }
    }

    /**
     * @param list<Site> $allowed
     */
    private function isAllowed(Identifier $site, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            if ($candidate->getId()->equals($site)) {
                return true;
            }
        }

        return false;
    }
}
