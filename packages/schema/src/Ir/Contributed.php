<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * A member that knows where it came from — the entity's own spec, or a pattern.
 *
 * Tracked so a sealed-collision error can name both contributors rather than just
 * reporting that something clashed.
 */
interface Contributed
{
    public function declaredIn(): Origin;
}
