<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use PheFr\Runtime\Identity\Identifier;

/**
 * One row-level change within a batch.
 */
interface WriteOperation
{
    public function entity(): string;

    public function target(): Identifier;
}
