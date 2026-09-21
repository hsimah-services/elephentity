<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Mutation\EntitySideEffects;
use Eleph\Runtime\Mutation\MutableMutationContext;
use Eleph\Runtime\SideEffect\SideEffectEvent;
use Eleph\Runtime\SideEffect\SideEffectPhase;

/**
 * A stand-in for the generated sideEffect bridge.
 */
final class RecordingSideEffects implements EntitySideEffects
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, callable(): void> */
    private array $failures = [];

    public function failOn(SideEffectPhase $phase, callable $failure): void
    {
        $this->failures[$phase->value] = $failure;
    }

    public function handlers(SideEffectPhase $phase, SideEffectEvent $event, MutableMutationContext $context): iterable
    {
        yield function () use ($phase, $event, $context): void {
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
        };
    }
}
