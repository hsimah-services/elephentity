<?php

declare(strict_types=1);

namespace PheFr\Runtime\Identity;

use Stringable;

/**
 * A handle to an entity row, whether or not it exists yet.
 *
 * A unit of work that creates a Post and its Comments in one commit must be able to
 * refer to the Post before the database has assigned it an id. Both states therefore
 * satisfy the same interface, and it is the unit of work's job to substitute real ids
 * once the writes they depend on have been flushed.
 */
interface Identifier extends Stringable
{
    public function isPersisted(): bool;

    public function equals(self $other): bool;
}
