<?php

declare(strict_types=1);

namespace PheFr\Runtime\UnitOfWork;

use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Identity\Identifier;
use PheFr\Runtime\Identity\PendingId;
use PheFr\Runtime\Mutation\Mutation;
use PheFr\Runtime\Storage\StorageAdaptor;
use PheFr\Runtime\Storage\Write\Insert;
use PheFr\Runtime\Storage\Write\Link;
use PheFr\Runtime\Storage\Write\Unlink;
use PheFr\Runtime\Storage\Write\Update;
use PheFr\Runtime\Storage\Write\WriteBatch;
use PheFr\Runtime\Storage\Write\WriteResult;
use PheFr\Runtime\Trigger\TriggerPhase;
use PheFr\Runtime\Verification\CommitRejected;

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
 */
final class UnitOfWork
{
    /** @var list<Mutation> */
    private array $mutations = [];

    public function __construct(
        private readonly StorageAdaptor $storage,
        private readonly VerificationPipeline $verification,
        private readonly ValueEncoder $encoder,
        private readonly TriggerDispatcher $triggers,
        private readonly DependencySorter $sorter = new DependencySorter(),
    ) {
    }

    public function register(Mutation $mutation): void
    {
        $this->mutations[] = $mutation;
    }

    public function isEmpty(): bool
    {
        return [] === $this->pending();
    }

    /**
     * @throws CommitRejected when verification fails; nothing is written.
     */
    public function commit(): WriteResult
    {
        $mutations = $this->pending();

        if ([] === $mutations) {
            return new WriteResult();
        }

        $this->verify($mutations);

        $ordered = $this->sorter->sort($mutations);

        $result = $this->storage->transaction(function () use ($ordered): WriteResult {
            $result = $this->storage->write(new WriteBatch(...$this->rowOperations($ordered)));

            $links = $this->linkOperations($ordered, $result);

            if ([] !== $links) {
                $this->storage->write(new WriteBatch(...$links));
            }

            // After the flush so ids exist, before COMMIT so a throw still undoes it.
            $this->triggers->dispatch(TriggerPhase::PreCommit, $ordered);

            return $result;
        });

        $this->mutations = [];

        $this->triggers->dispatch(TriggerPhase::PostCommit, $ordered);

        return $result;
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
