<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

/**
 * What depends on each entity.
 *
 * Supplied by generated code, since the edge graph is a build-time fact.
 */
interface DeletionRules
{
    /**
     * @return list<DeletionRule>
     */
    public function for(string $entity): array;
}
