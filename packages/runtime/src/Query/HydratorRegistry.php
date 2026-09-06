<?php

declare(strict_types=1);

namespace Eleph\Runtime\Query;

use Eleph\Runtime\Boot\MissingImplementations;

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
