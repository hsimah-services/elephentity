<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\SideEffect\SideEffectEvent;
use Eleph\Runtime\SideEffect\SideEffectPhase;

interface EntitySideEffects
{
    /** @return iterable<callable(): void> */
    public function handlers(SideEffectPhase $phase, SideEffectEvent $event, MutableMutationContext $context): iterable;
}
