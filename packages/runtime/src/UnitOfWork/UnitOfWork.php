<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use DateTimeImmutable;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\SideEffect\SideEffectPhase;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Storage\Write\WriteOperation;
use Eleph\Runtime\Storage\Write\WriteResult;
use Eleph\Runtime\Verification\CommitRejected;
use RuntimeException;

/**
 * Commit order: pre-commit side effects, stamp, verify, encode, transactional writes,
 * post-commit side effects. Storage failures roll back; post-commit writes are independent.
 */
final class UnitOfWork
{
    /** @var list<Mutation> */
    private array $mutations = [];

    /** @var list<Deletion> */
    private array $deletions = [];

    public function __construct(
        private readonly StorageAdaptor $storage,
        private readonly VerificationPipeline $verification,
        private readonly ValueEncoder $encoder,
        private readonly SideEffectDispatcher $sideEffects,
        private readonly DependencySorter $sorter = new DependencySorter(),
        /**
         * Empty by default: a project declaring no managed field needs nothing here,
         * and an empty map costs one array lookup per commit.
         */
        private readonly ManagedFields $managed = new ManagedFields(),
        /**
         * Absent when a project has no deletions to plan. Optional rather than
         * required because it needs the edge graph, which not every caller has.
         */
        private readonly ?DeletionPlanner $planner = null,
        /**
         * Absent when nothing is unique, and when a caller does not want the extra
         * read. The index remains the guarantee either way.
         */
        private readonly ?UniquenessCheck $unique = null,
    ) {
    }

    public function register(Mutation $mutation): void
    {
        $this->mutations[] = $mutation;
    }

    /**
     * Remove a row, and whatever its edges say goes with it.
     */
    public function delete(Deletion $deletion): void
    {
        $this->deletions[] = $deletion;
    }

    public function isEmpty(): bool
    {
        return [] === $this->pending() && [] === $this->deletions;
    }

    /**
     * @throws CommitRejected when verification fails; nothing is written.
     */
    public function commit(): WriteResult
    {
        $mutations = $this->mutations;
        $deletions = $this->deletions;

        if ([] === $mutations && [] === $deletions) {
            return new WriteResult();
        }

        $this->sideEffects->dispatch(SideEffectPhase::PreCommit, $mutations);
        $mutations = $this->pending();
        if ([] === $mutations && [] === $deletions) {
            $completed = $this->mutations;
            $this->mutations = [];
            $this->sideEffects->dispatch(SideEffectPhase::PostCommit, $completed);
            return new WriteResult();
        }

        // Plan removals before custom logic, then verify that the plan still holds
        // inside the transaction. No side effect is invoked after writes begin.
        $plannedRemovals = $this->deletionOperations($deletions);
        $removed = $this->deletionContexts($plannedRemovals);
        $this->sideEffects->dispatchDeletions(SideEffectPhase::PreCommit, $removed);
        $deletionChanges = array_values(array_filter($removed, static fn (Mutation $mutation): bool => !$mutation->isEmpty()));
        $allChanges = [...$mutations, ...$deletionChanges];

        $now = new DateTimeImmutable();
        foreach ($allChanges as $mutation) {
            $this->managed->stamp($mutation, $now);
        }
        $this->verify($allChanges);
        $ordered = $this->sorter->sort($allChanges);
        $rows = $this->rowOperations($ordered);

        $result = $this->storage->transaction(function () use ($ordered, $deletions, $rows, $plannedRemovals): WriteResult {
            $result = $this->storage->write(new WriteBatch(...$rows));
            $this->resolveIds($ordered, $result);
            $links = $this->linkOperations($ordered, $result);
            if ([] !== $links) {
                $this->storage->write(new WriteBatch(...$links));
            }

            $removals = $this->deletionOperations($deletions);
            if ($removals != $plannedRemovals) {
                throw new RuntimeException('The deletion graph changed during this mutation. Retry against the current state.');
            }
            if ([] !== $removals) {
                $this->storage->write(new WriteBatch(...$removals));
            }

            return $result;
        });

        $this->mutations = [];
        $this->deletions = [];

        $this->sideEffects->dispatch(SideEffectPhase::PostCommit, $mutations);
        $this->sideEffects->dispatchDeletions(SideEffectPhase::PostCommit, $removed);

        return $result;
    }

    /**
     * One context per row the plan removes, in the order the plan removes them.
     *
     * A deletion carries no pending values, so the context holds identity and nothing
     * else. A trigger that needs the row reads it — which is why the preCommit pass
     * runs before the DELETE rather than after.
     *
     * @param list<WriteOperation> $removals
     *
     * @return list<Mutation>
     */
    private function deletionContexts(array $removals): array
    {
        $contexts = [];

        foreach ($removals as $operation) {
            if (!$operation instanceof Delete) {
                continue;
            }

            $target = $operation->target();

            if ($target instanceof EntityId) {
                $contexts[] = new Mutation($operation->entity(), $target);
            }
        }

        return $contexts;
    }

    /**
     * @param list<Mutation> $mutations
     *
     * @throws CommitRejected
     */
    private function verify(array $mutations): void
    {
        $violations = [];

        foreach ($mutations as $mutation) {
            foreach ($this->verification->verify($mutation) as $violation) {
                $violations[] = $violation;
            }

            foreach ($this->unique?->check($mutation) ?? [] as $violation) {
                $violations[] = $violation;
            }
        }

        if ([] !== $violations) {
            throw new CommitRejected($violations);
        }
    }

    /**
     * @param list<Mutation> $mutations
     *
     * @return list<Insert|Update>
     */
    private function rowOperations(array $mutations): array
    {
        $operations = [];

        foreach ($mutations as $mutation) {
            $values = [];

            foreach ($mutation->changes() as $field => $value) {
                $values[$field] = $this->encoder->encode($mutation->entity(), $field, $value);
            }

            $target = $mutation->target();

            if ($target instanceof PendingId) {
                $operations[] = new Insert($mutation->entity(), $target, $values);

                continue;
            }

            if ($target instanceof EntityId && [] !== $values) {
                $operations[] = new Update($mutation->entity(), $target, $values);
            }
        }

        return $operations;
    }

    /**
     * Links are written after rows, with every pending id replaced by the real one.
     *
     * @param list<Mutation> $mutations
     *
     * @return list<Link|Unlink>
     */
    private function linkOperations(array $mutations, WriteResult $result): array
    {
        $operations = [];

        foreach ($mutations as $mutation) {
            $from = $this->resolve($mutation->target(), $result);

            foreach ($mutation->edgeChanges() as $edge) {
                if ($edge->isReplacement()) {
                    // Clear first: a replacement says what the edge holds, not what to
                    // add to it.
                    $operations[] = new Unlink($mutation->entity(), $edge->name, $from);
                }

                foreach ($edge->removed() as $target) {
                    $operations[] = new Unlink(
                        $mutation->entity(),
                        $edge->name,
                        $from,
                        $this->resolve($target, $result),
                    );
                }

                foreach ($edge->added() as $target) {
                    $operations[] = new Link(
                        $mutation->entity(),
                        $edge->name,
                        $from,
                        $this->resolve($target, $result),
                    );
                }
            }
        }

        return $operations;
    }

    /**
     * @param list<Deletion> $deletions
     *
     * @return list<WriteOperation>
     */
    private function deletionOperations(array $deletions): array
    {
        if ([] === $deletions) {
            return [];
        }

        if (null === $this->planner) {
            throw new RuntimeException(
                'This unit of work was built without a DeletionPlanner, so it cannot delete. Supply one, or do not register deletions.',
            );
        }

        return $this->planner->plan($deletions);
    }

    private function resolve(Identifier $identifier, WriteResult $result): Identifier
    {
        if ($identifier instanceof PendingId && $result->wasAssigned($identifier)) {
            return $result->idFor($identifier);
        }

        return $identifier;
    }

    /**
     * @param list<Mutation> $mutations
     */
    private function resolveIds(array $mutations, WriteResult $result): void
    {
        foreach ($mutations as $mutation) {
            $target = $mutation->target();

            if ($target instanceof PendingId && $result->wasAssigned($target)) {
                $mutation->resolveId($result->idFor($target));
            }
        }
    }

    /**
     * @return list<Mutation>
     */
    private function pending(): array
    {
        return array_values(array_filter(
            $this->mutations,
            static fn (Mutation $mutation): bool => !$mutation->isEmpty(),
        ));
    }
}
