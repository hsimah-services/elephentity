<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Mutation\EntityTriggers;
use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Runtime\Trigger\TriggerEvent;
use Eleph\Runtime\Trigger\TriggerPhase;

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
        $this->calls[] = sprintf(
            '%s:%s:%s:%s',
            $phase->value,
            $event->value,
            $context->entity(),
            $context->id(),
        );

        $failure = $this->failures[$phase->value] ?? null;

        if (null !== $failure) {
            $failure();
        }
    }
}
