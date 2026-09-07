<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Mutation\EntityTriggers;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Trigger\TriggerEvent;
use Eleph\Runtime\Trigger\TriggerPhase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runs an entity's triggers for one phase.
 *
 * The framework does not classify a trigger as critical or not — the trigger knows and
 * the framework should not guess. What differs is what a throw can still achieve:
 *
 *   preCommit  runs inside the transaction, so an exception propagates and takes the
 *              whole commit with it.
 *   postCommit runs after COMMIT, where there is nothing left to roll back. An
 *              exception is logged and the remaining triggers still run, because one
 *              failing notification should not silently cancel the others.
 */
final readonly class TriggerDispatcher
{
    /**
     * @param array<string, EntityTriggers> $triggers Keyed by entity name.
     */
    public function __construct(
        private array $triggers,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param list<Mutation> $mutations
     */
    public function dispatch(TriggerPhase $phase, array $mutations): void
    {
        foreach ($mutations as $mutation) {
            $this->run(
                $phase,
                $mutation->isCreate() ? TriggerEvent::Create : TriggerEvent::Update,
                $mutation,
            );
        }
    }

    /**
     * Delete events, for every row the commit removes.
     *
     * Separate because the event cannot be derived from the context: a deletion has no
     * pending values to inspect, and a Mutation standing in for one is indistinguishable
     * from an update that happens to change nothing.
     *
     * @param list<Mutation> $deleted One per planned removal, cascades included.
     */
    public function dispatchDeletions(TriggerPhase $phase, array $deleted): void
    {
        foreach ($deleted as $context) {
            $this->run($phase, TriggerEvent::Delete, $context);
        }
    }

    private function run(TriggerPhase $phase, TriggerEvent $event, Mutation $context): void
    {
        $triggers = $this->triggers[$context->entity()] ?? null;

        if (null === $triggers) {
            return;
        }

        if (TriggerPhase::PreCommit === $phase) {
            $triggers->dispatch($phase, $event, $context);

            return;
        }

        try {
            $triggers->dispatch($phase, $event, $context);
        } catch (Throwable $exception) {
            $this->logger->error('A postCommit trigger failed after the data was committed.', [
                'entity' => $context->entity(),
                'event' => $event->value,
                'exception' => $exception,
            ]);
        }
    }
}
