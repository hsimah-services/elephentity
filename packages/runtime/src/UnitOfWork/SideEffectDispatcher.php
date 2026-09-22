<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Mutation\EntitySideEffects;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\SideEffect\SideEffectEvent;
use Eleph\Runtime\SideEffect\SideEffectPhase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * PreCommit exceptions cancel before persistence. PostCommit exceptions are logged; remaining
 * handlers still run.
 */
final readonly class SideEffectDispatcher
{
    /**
     * @param array<string, EntitySideEffects> $sideEffects Keyed by entity name.
     */
    public function __construct(
        private array $sideEffects,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<Mutation> $mutations
     */
    public function dispatch(SideEffectPhase $phase, array $mutations): void
    {
        foreach ($mutations as $mutation) {
            $this->run(
                $phase,
                $mutation->isCreate() ? SideEffectEvent::Create : SideEffectEvent::Update,
                $mutation,
            );
        }
    }

    /**
     * Dispatches explicit delete events; deletion contexts cannot distinguish deletion from an
     * unchanged update.
     *
     * @param list<Mutation> $deleted One per planned removal, cascades included.
     */
    public function dispatchDeletions(SideEffectPhase $phase, array $deleted): void
    {
        foreach ($deleted as $context) {
            $this->run($phase, SideEffectEvent::Delete, $context);
        }
    }

    private function run(SideEffectPhase $phase, SideEffectEvent $event, Mutation $context): void
    {
        $sideEffects = $this->sideEffects[$context->entity()] ?? null;

        if (null === $sideEffects) {
            return;
        }

        foreach ($sideEffects->handlers($phase, $event, $context) as $handler) {
            if (SideEffectPhase::PreCommit === $phase) {
                $handler();
                continue;
            }

            try {
                $handler();
            } catch (Throwable $exception) {
                $this->logger->error('A post-commit side effect failed after the data was committed.', [
                    'entity' => $context->entity(),
                    'event' => $event->value,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
