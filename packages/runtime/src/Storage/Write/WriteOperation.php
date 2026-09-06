<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage\Write;

use Eleph\Runtime\Identity\Identifier;

/**
 * One row-level change within a batch.
 */
interface WriteOperation
{
    public function entity(): string;

    public function target(): Identifier;
}
