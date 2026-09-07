<?php

declare(strict_types=1);

namespace Clog;

use Clog\Contract\DefaultExpiryIsPaired;
use Clog\Contract\ItemSearch;
use Clog\Entity\Catalogue;
use Clog\Entity\Inventory\InventoryHydrator;
use Clog\Entity\Inventory\InventoryInput;
use Clog\Entity\Inventory\InventoryTriggers;
use Clog\Entity\Inventory\InventoryVerifiers;
use Clog\Entity\Item\Contract\ItemDefaultExpiryUnitVerifier;
use Clog\Entity\Item\Contract\ItemDefaultExpiryValueVerifier;
use Clog\Entity\Item\Contract\ItemSearchQuery;
use Clog\Entity\Item\ItemFinder;
use Clog\Entity\Item\ItemHydrator;
use Clog\Entity\Item\ItemInput;
use Clog\Entity\Item\ItemTriggers;
use Clog\Entity\Item\ItemVerifiers;
use Clog\Entity\Location\LocationHydrator;
use Clog\Entity\Location\LocationInput;
use Clog\Entity\Location\LocationTriggers;
use Clog\Entity\Location\LocationVerifiers;
use Eleph\Runtime\Catalogue\BootCheck;
use Eleph\Runtime\Gateway\Runtime;
use Eleph\Runtime\Gateway\UnitOfWorkFactory;
use Eleph\Runtime\Query\Queries;
use Eleph\Runtime\Query\ValueDecoder;
use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Manifest\StorageManifest;
use Eleph\WordPress\Migration\MigrationPlan;
use Eleph\WordPress\Migration\SchemaInstaller;
use Eleph\WordPress\WordPress;
use Eleph\WPGraphQL\Plugin;

/**
 * Everything between "the code is generated" and "the application runs".
 *
 * The order is the whole content of this file, and none of it is arbitrary:
 *
 *   1. The **storage manifest** and the **adaptor**. The manifest is generated; the
 *      table prefix is not, because a WordPress install can use any and multisite uses
 *      one per site, so it arrives from `$wpdb`.
 *   2. The **container**, holding the generated classes and — the point of the
 *      exercise — the interfaces the spec said you would have to implement.
 *   3. The **catalogue**, which is generated and resolves everything through that
 *      container, so adding an entity never widens a signature here.
 *   4. The **runtime**, the one object an application holds.
 *   5. `BootCheck`, which refuses to start while any contract is unbound and names
 *      every missing one at once.
 *
 * There is a knot in the middle: `ItemSearch` needs a query builder, which needs the
 * runtime, which needs the catalogue, which needs the container `ItemSearch` lives in.
 * Container factories are closures and nothing is resolved until something asks, so
 * binding it after the runtime exists is all the ceremony required.
 *
 * Deliberately one flat file with no cleverness in it. A container that autowires
 * would make this shorter and would teach nothing.
 */
final class Bootstrap
{
    private const GENERATED = __DIR__ . '/../generated';

    /**
     * Each manifest sits in its own target's directory, inside the PHP tree.
     *
     * They are PHP the runtime loads by path, so they belong here — but the PHP builder
     * cannot produce them, since compiling a storage schema needs code that knows what
     * a table is. So they come from targets of their own, and a project that installs
     * neither the driver nor the integration has neither directory.
     */
    private const STORAGE_MANIFEST = self::GENERATED . '/wordpress/storage-manifest.php';

    private const GRAPHQL_MANIFEST = self::GENERATED . '/wpgraphql/graphql-manifest.php';

    private ?Runtime $runtime = null;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * The assembled framework, checked, built once.
     */
    public function runtime(): Runtime
    {
        if (null !== $this->runtime) {
            return $this->runtime;
        }

        $storage = WordPress::adaptor($this->database, $this->manifest());
        $container = new Container();
        $catalogue = new Catalogue($container);

        $runtime = new Runtime(
            $storage,
            $catalogue,
            new UnitOfWorkFactory($storage, $catalogue, new NoProcessors()),
        );

        $this->register($container, $runtime);

        // Boot time, not call time: a missing handler must not lurk in production
        // until the one request that needs it arrives.
        (new BootCheck($catalogue, $container))->run();

        return $this->runtime = $runtime;
    }

    /**
     * The GraphQL layer, for a project that speaks it.
     *
     * Hook `boot()` on `plugins_loaded`. Nothing else in the application depends on
     * this object existing — a project that speaks no GraphQL never loads it.
     */
    public function graphql(): Plugin
    {
        return Plugin::fromManifest(
            self::GRAPHQL_MANIFEST,
            $this->runtime(),
            new NoProcessors(),
        );
    }

    /**
     * Create or migrate the tables. Call on plugin activation.
     *
     * Additive changes are applied; anything destructive or ambiguous is refused and
     * then *nothing* is applied, so the plan comes back for the caller to log or to
     * fail activation on.
     */
    public function install(): MigrationPlan
    {
        return (new SchemaInstaller($this->database, $this->manifest()))->install();
    }

    /**
     * Everything the catalogue will ask for, and nothing it will not.
     *
     * Two kinds of entry, and the difference is the whole workflow: the generated
     * classes, which are mechanical and could be autowired; and the contracts under
     * `generated/*​/Contract/`, which are the list of things you owe this spec.
     */
    private function register(Container $container, Runtime $runtime): void
    {
        $decoder = new ValueDecoder();

        $container
            // Shared plumbing. The query builder is bound as a closure so it can
            // capture a runtime that is finished being built by the time it runs.
            ->set(ValueDecoder::class, static fn (): ValueDecoder => $decoder)
            ->set(Queries::class, static fn (): Queries => $runtime->queries())

            // Generated: one hydrator, one input applier, one verifier bridge and one
            // trigger bridge per entity.
            ->set(ItemHydrator::class, static fn (): object => new ItemHydrator($decoder))
            ->set(ItemInput::class, static fn (): object => new ItemInput($decoder))
            ->set(ItemTriggers::class, static fn (): object => new ItemTriggers())
            ->set(ItemVerifiers::class, static fn (Container $c): object => new ItemVerifiers(
                $c->get(ItemDefaultExpiryUnitVerifier::class),
                $c->get(ItemDefaultExpiryValueVerifier::class),
            ))
            ->set(ItemFinder::class, static fn (Container $c): object => new ItemFinder(
                $c->get(ItemSearchQuery::class),
            ))

            ->set(LocationHydrator::class, static fn (): object => new LocationHydrator($decoder))
            ->set(LocationInput::class, static fn (): object => new LocationInput($decoder))
            ->set(LocationTriggers::class, static fn (): object => new LocationTriggers())
            ->set(LocationVerifiers::class, static fn (): object => new LocationVerifiers())

            ->set(InventoryHydrator::class, static fn (): object => new InventoryHydrator($decoder))
            ->set(InventoryInput::class, static fn (): object => new InventoryInput($decoder))
            ->set(InventoryTriggers::class, static fn (): object => new InventoryTriggers())
            ->set(InventoryVerifiers::class, static fn (): object => new InventoryVerifiers())

            // Yours. `ls generated/*/Contract/` is exactly this list, and BootCheck
            // fails by name for anything missing from it.
            ->bind(ItemSearchQuery::class, static fn (Container $c): object => new ItemSearch(
                $c->get(Queries::class),
                $c->get(ItemHydrator::class),
            ))
            ->bind(ItemDefaultExpiryUnitVerifier::class, static fn (): object => new DefaultExpiryIsPaired())
            ->bind(ItemDefaultExpiryValueVerifier::class, static fn (Container $c): object => $c->get(
                ItemDefaultExpiryUnitVerifier::class,
            ));
    }

    private function manifest(): StorageManifest
    {
        return WordPress::manifest(self::STORAGE_MANIFEST);
    }
}
