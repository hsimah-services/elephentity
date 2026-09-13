<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

interface EntityReadPolicies
{
    public function decide(object $entity, Viewer $viewer): PolicyDecision;

    public function isEmpty(): bool;
}
