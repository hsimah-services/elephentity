<?php

declare(strict_types=1);

namespace PheFr\Runtime\Tests\UnitOfWork;

use PheFr\Runtime\Mutation\EntityTriggers;
use PheFr\Runtime\Mutation\MutationContext;
use PheFr\Runtime\Trigger\TriggerEvent;
use PheFr\Runtime\Trigger\TriggerPhase;

/**
 * A stand-in for the generated trigger bridge.
 */
final class RecordingTriggers implements EntityTriggers
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, callable(): void> */
    private array $failures = [];

    public function failOn(TriggerPhase $phase, callable $failure): void
    {
        $this->failures[$phase->value] = $failure;
    }

    public function dispatch(TriggerPhase $phase, TriggerEvent $event, MutationContext $context): void
    {
        $this->calls[] = sprintf('%s:%s:%s', $phase->value, $event->value, $context->entity());

        $failure = $this->failures[$phase->value] ?? null;

        if (null !== $failure) {
            $failure();
        }
    }
}
