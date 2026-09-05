<?php

declare(strict_types=1);

namespace PheFr\Runtime\Query;

use PheFr\Runtime\Boot\MissingImplementations;

/**
 * Finds the hydrator for an entity name.
 *
 * @see MissingImplementations
 */
interface HydratorRegistry
{
    public function has(string $entity): bool;

    /**
     * @return Hydrator<object>
     */
    public function get(string $entity): Hydrator;
}
