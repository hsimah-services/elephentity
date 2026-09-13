<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

use RuntimeException;

final class AccessDenied extends RuntimeException
{
    public function __construct(
        public readonly string $entity,
        public readonly ?string $policy,
        public readonly string $reason,
    ) {
        $label = null === $policy ? 'unknown' : sprintf('"%s"', $policy);

        parent::__construct(sprintf('%s: %s (policy %s)', $entity, $reason, $label));
    }
}
