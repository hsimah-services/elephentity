<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Relay;

use WPGraphQL\Data\Loader\AbstractDataLoader;

/**
 * The WPGraphQL data loader every Elephentity node resolves through.
 *
 * Deliberately empty of decisions — WPGraphQL constructs this itself with nothing but
 * an AppContext, so everything it would need has to come from NodeRuntime anyway, and
 * putting the work there is what lets it be tested without WordPress.
 *
 * One find per key rather than one query per batch: EntityGateway addresses rows one
 * at a time, and `node` is asked for a handful of ids at most. A batched load would be
 * a gateway change, not a loader change.
 */
final class EntityLoader extends AbstractDataLoader
{
    /**
     * @param array<int, int|string> $keys
     *
     * @return array<int|string, object|null>
     */
    protected function loadKeys(array $keys): array // phpcs:ignore
    {
        $loaded = [];

        foreach ($keys as $key) {
            $loaded[$key] = NodeRuntime::load((string) $key);
        }

        return $loaded;
    }
}
