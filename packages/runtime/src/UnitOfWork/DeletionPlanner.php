<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\DeletionPolicy;
use Eleph\Runtime\Storage\DeletionRules;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\WriteOperation;
use RuntimeException;

/**
 * Turns "delete this row" into everything that entails.
 *
 * Walks the edges that point at the row, applies each policy, and recurses where one
 * says cascade — a Post taking its Comments with it, and each Comment taking whatever
 * depends on *it*.
 *
 * Two things the walk has to survive. Cycles: Post cascades to Comment, Comment
 * cascades back to Post, and without a visited set that runs until the stack gives
 * out. And ordering: dependents are deleted before the row they depend on, so a
 * foreign key never dangles even briefly.
 */
final readonly class DeletionPlanner
{
    private const MAX_DEPTH = 32;

    public function __construct(
        private StorageAdaptor $storage,
        private DeletionRules $rules,
    ) {
    }

    /**
     * @param list<Deletion> $deletions
     *
     * @return list<WriteOperation>
     */
    public function plan(array $deletions): array
    {
        $operations = [];
        $visited = [];

        foreach ($deletions as $deletion) {
            foreach ($this->expand($deletion, $visited, 0) as $operation) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    /**
     * @param array<string, true> $visited
     *
     * @return list<WriteOperation>
     */
    private function expand(Deletion $deletion, array &$visited, int $depth): array
    {
        if (isset($visited[$deletion->key()])) {
            return [];
        }

        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException(sprintf(
                'Deleting %s cascades more than %d levels deep. That is almost certainly a policy loop rather than a real graph.',
                $deletion->key(),
                self::MAX_DEPTH,
            ));
        }

        $visited[$deletion->key()] = true;

        $operations = [];

        foreach ($this->rules->for($deletion->entity) as $rule) {
            // Rules already select the dependent side. Deleting the declaring row
            // (Post.comments) reads along; deleting the target (Inventory.item)
            // reads back to the declaring rows that depend on it.
            $reversed = $deletion->entity !== $rule->declaredBy;
            $link = $reversed
                ? EdgeFilter::back($rule->declaredBy, $rule->edge, $deletion->id)
                : EdgeFilter::along($rule->declaredBy, $rule->edge, $deletion->id);
            $criteria = Criteria::for($rule->dependent)->linkedTo($link);

            if (DeletionPolicy::Restrict === $rule->policy) {
                $remaining = $this->storage->count($criteria);

                if ($remaining > 0) {
                    throw new RuntimeException(sprintf(
                        'Cannot delete %s: %d %s still depend%s on it through "%s". The edge says restrict.',
                        $deletion->key(),
                        $remaining,
                        $rule->dependent,
                        1 === $remaining ? 's' : '',
                        $rule->edge,
                    ));
                }

                continue;
            }

            $dependents = null;

            // Unlink is always expressed from the declaring side, even when the
            // deleted row is on the other end. Use pairs in that direction so other
            // links of a surviving dependent are preserved.
            if ($rule->viaJoinTable || DeletionPolicy::Nullify === $rule->policy) {
                if ($reversed) {
                    $dependents = $this->dependents($criteria);
                    foreach ($dependents as $id) {
                        $operations[] = new Unlink($rule->declaredBy, $rule->edge, $id, $deletion->id);
                    }
                } else {
                    $operations[] = new Unlink($rule->declaredBy, $rule->edge, $deletion->id);
                }
            }

            if (DeletionPolicy::Nullify === $rule->policy) {
                continue;
            }

            foreach ($dependents ?? $this->dependents($criteria) as $id) {
                foreach ($this->expand(new Deletion($rule->dependent, $id), $visited, $depth + 1) as $operation) {
                    $operations[] = $operation;
                }
            }
        }

        // Last, so nothing is left pointing at a row that has already gone.
        $operations[] = new Delete($deletion->entity, $deletion->id);

        return $operations;
    }

    /**
     * @return list<EntityId>
     */
    private function dependents(Criteria $criteria): array
    {
        return array_map(
            static fn ($record): EntityId => $record->id,
            $this->storage->query($criteria->unbounded())->items,
        );
    }
}
