<?php

declare(strict_types=1);

namespace PheFr\Runtime\Capability;

use RuntimeException;

final class UnsupportedCapability extends RuntimeException
{
    public static function for(Capability $capability, string $adaptor): self
    {
        return new self(sprintf(
            'The %s storage adaptor does not support %s.',
            $adaptor,
            $capability->value,
        ));
    }
}
