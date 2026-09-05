<?php

declare(strict_types=1);

namespace PheFr\Runtime\Query;

use PheFr\Runtime\Storage\Record;

/**
 * Turns a stored row into an entity.
 *
 * Generated per entity, because only generated code can call a generated constructor
 * with exact types. Read processors run here, on the way up.
 *
 * @template T of object
 */
interface Hydrator
{
    /**
     * @return T
     */
    public function hydrate(Record $record, EdgeLoader $edges): object;
}
