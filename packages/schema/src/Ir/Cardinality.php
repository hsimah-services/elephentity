<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * How many of the target this edge reaches. The reverse side is described by
 * EdgeInverse::$unique, and the two together determine storage.
 */
enum Cardinality: string
{
    case One = 'one';
    case Many = 'many';
}
