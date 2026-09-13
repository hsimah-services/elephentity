<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

interface EntityWritePolicies
{
    public function decide(?object $entity, WriteContext $context, Viewer $viewer): PolicyDecision;

    public function isEmpty(): bool;
}
