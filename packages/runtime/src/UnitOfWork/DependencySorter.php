<?php

declare(strict_types=1);

namespace PheFr\Runtime\UnitOfWork;

use PheFr\Runtime\Identity\Identifier;
use PheFr\Runtime\Mutation\Mutation;
use RuntimeException;

/**
 * Orders mutations so that a row is written before anything that references it.
 *
 * This exists because ids are server-generated. A commit creating a Post and its
 * Comments must insert the Post, read back its id, and only then write the Comments —
 * so the batch must arrive already sorted, and the adaptor never has to reason about
 * order.
 *
 * The sort is stable: mutations with no relationship keep the order they were
 * registered in, so a commit's SQL is reproducible and diffable in a log.
 */
final readonly class DependencySorter
{
    /**
     * @param list<Mutation> $mutations
     *
     * @return list<Mutation>
     *
     * @throws RuntimeException on a dependency cycle, which no ordering can satisfy.
     */
    public function sort(array $mutations): array
    {
        /** @var array<string, Mutation> $byTarget */
        $byTarget = [];

        foreach ($mutations as $mutation) {
            $byTarget[$this->key($mutation->target())] = $mutation;
        }

        $sorted = [];
        $state = [];

        foreach ($mutations as $mutation) {
            $this->visit($mutation, $byTarget, $sorted, $state, []);
        }

        return array_values($sorted);
    }

    /**
     * @param array<string, Mutation> $byTarget
     * @param array<string, Mutation> $sorted
     * @param array<string, bool>     $state    true once emitted, false while visiting.
     * @param list<string>            $trail
     */
    private function visit(
        Mutation $mutation,
        array $byTarget,
        array &$sorted,
        array &$state,
        array $trail,
    ): void {
        $key = $this->key($mutation->target());

        if (isset($state[$key])) {
            if (false === $state[$key]) {
                throw new RuntimeException(sprintf(
                    'These writes depend on each other and cannot be ordered: %s.',
                    implode(' → ', [...$trail, $key]),
                ));
            }

            return;
        }

        $state[$key] = false;

        foreach ($mutation->dependencies() as $dependency) {
            $required = $byTarget[$this->key($dependency)] ?? null;

            if (null === $required) {
                // A link to a pending row nothing in this commit creates. The commit
                // cannot resolve it, so say so here rather than writing a null column.
                throw new RuntimeException(sprintf(
                    '%s links to %s, which no mutation in this commit creates.',
                    $mutation->entity(),
                    $dependency,
                ));
            }

            $this->visit($required, $byTarget, $sorted, $state, [...$trail, $key]);
        }

        $state[$key] = true;
        $sorted[$key] = $mutation;
    }

    private function key(Identifier $identifier): string
    {
        return (string) $identifier;
    }
}
