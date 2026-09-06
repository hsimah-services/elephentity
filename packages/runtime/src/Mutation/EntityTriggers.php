<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Trigger\TriggerEvent;
use Eleph\Runtime\Trigger\TriggerPhase;

/**
 * The bridge between the runtime and one entity's typed trigger handlers.
 *
 * Same reasoning as EntityVerifiers: a handler takes a PostMutationContext, so the
 * generator emits the wrapper that knows how to build one.
 *
 * Order is declaration order from the spec — deterministic, visible in the diff, and
 * free of priority-number archaeology.
 */
interface EntityTriggers
{
    public function dispatch(TriggerPhase $phase, TriggerEvent $event, MutationContext $context): void;
}
