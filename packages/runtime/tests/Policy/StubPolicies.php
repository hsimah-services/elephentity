<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Policy;

use Closure;
use Eleph\Runtime\Policy\EntityReadPolicies;
use Eleph\Runtime\Policy\EntityWritePolicies;
use Eleph\Runtime\Policy\PolicyDecision;
use Eleph\Runtime\Policy\Viewer;
use Eleph\Runtime\Policy\WriteContext;

final class StubPolicies implements EntityReadPolicies
{
    public int $calls = 0;

    /** @param Closure(object, Viewer): PolicyDecision $handler */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function decide(object $entity, Viewer $viewer): PolicyDecision
    {
        ++$this->calls;

        return ($this->handler)($entity, $viewer);
    }

    public function isEmpty(): bool
    {
        return false;
    }
}

final class StubWritePolicies implements EntityWritePolicies
{
    public int $calls = 0;

    /** @param Closure(?object, WriteContext, Viewer): PolicyDecision $handler */
    public function __construct(private readonly Closure $handler)
    {
    }

    public function decide(?object $entity, WriteContext $context, Viewer $viewer): PolicyDecision
    {
        ++$this->calls;

        return ($this->handler)($entity, $context, $viewer);
    }

    public function isEmpty(): bool
    {
        return false;
    }
}
