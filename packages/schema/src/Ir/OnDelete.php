<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * What happens to the far side of an edge when this entity is deleted.
 *
 * Defaults to Restrict so that orphaning data takes a deliberate keystroke.
 */
enum OnDelete: string
{
    case Restrict = 'restrict';
    case Cascade = 'cascade';
    case Nullify = 'nullify';
}
