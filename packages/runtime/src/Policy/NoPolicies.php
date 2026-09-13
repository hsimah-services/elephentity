<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

final readonly class NoPolicies implements EntityReadPolicies, EntityWritePolicies
{
    public function decide(?object $entity, Viewer|WriteContext $context, ?Viewer $viewer = null): PolicyDecision
    {
        return PolicyDecision::allow();
    }

    public function isEmpty(): bool
    {
        return true;
    }
}
