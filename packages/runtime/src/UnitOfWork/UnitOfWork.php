<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Storage\Write\WriteOperation;
use Eleph\Runtime\Storage\Write\WriteResult;
use Eleph\Runtime\Trigger\TriggerPhase;
use Eleph\Runtime\Verification\CommitRejected;
use RuntimeException;

/**
 * One commit: everything registered, verified together, written in dependency order.
 *
 * The sequence is deliberate.
 *
 *   1. Verify every mutation before writing anything, so a rejected commit leaves no
 *      partial state and reports every violation at once.
 *   2. Sort by dependency, because server-generated ids mean a Post must be inserted
 *      before the Comments that reference it.
 *   3. Inside a transaction, write rows first and links second — a link needs both
 *      ends to exist.
 *   4. Still inside the transaction, run preCommit triggers. After the flush, so ids
 *      are real; before COMMIT, so a throw still rolls everything back.
 *   5. After COMMIT, run postCommit triggers, where writes are a new unit of work.
 *
 * Delete events are the one place the ordering is reversed: a preCommit delete trigger
 * runs *before* the rows go, because a trigger told about a deletion it can no longer
 * read is no use for an audit trail or an external projection. It still runs inside the
 * transaction, so a throw still undoes everything.
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
        private readonly TriggerDispatcher $triggers,
        private readonly DependencySorter $sorter = new DependencySorter(),
        /**
         * Absent when a project has no deletions to plan. Optional rather than
         * required because it needs the edge graph, which not every caller has.
         */
        private readonly ?DeletionPlanner $planner = null,
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
        $mutations = $this->pending();
        $deletions = $this->deletions;

        if ([] === $mutations && [] === $deletions) {
            return new WriteResult();
        }

        $this->verify($mutations);

        $ordered = $this->sorter->sort($mutations);

        /** @var list<Mutation> $removed */
        $removed = [];

        $result = $this->storage->transaction(function () use ($ordered, $deletions, &$removed): WriteResult {
            $result = $this->storage->write(new WriteBatch(...$this->rowOperations($ordered)));

            $links = $this->linkOperations($ordered, $result);

            if ([] !== $links) {
                $this->storage->write(new WriteBatch(...$links));
            }

            // Deletions last: a cascade counts and reads dependents, and doing that
            // before the writes in this same commit would see a stale graph.
            $removals = $this->deletionOperations($deletions);
            $removed = $this->deletionContexts($removals);

            // Before the rows go, so a trigger can still read what it is being told
            // about. Every planned removal is announced, cascades included — which is
            // the only way an application maintaining its own projection can keep up.
            $this->triggers->dispatchDeletions(TriggerPhase::PreCommit, $removed);

            if ([] !== $removals) {
                $this->storage->write(new WriteBatch(...$removals));
            }

            // After the flush so ids exist, before COMMIT so a throw still undoes it.
            $this->triggers->dispatch(TriggerPhase::PreCommit, $ordered);

            return $result;
        });

        $this->mutations = [];
        $this->deletions = [];

        $this->triggers->dispatch(TriggerPhase::PostCommit, $ordered);
        $this->triggers->dispatchDeletions(TriggerPhase::PostCommit, $removed);

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
