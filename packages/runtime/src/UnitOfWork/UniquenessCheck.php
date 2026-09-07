<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Verification\FieldViolation;
use Eleph\Runtime\Verification\Violation;

/**
 * Asks whether a value marked `unique:` is already taken.
 *
 * Without it a duplicate is a raw SQL exception — the driver's message, the statement
 * that produced it, and an index name no caller has ever heard of. Compare what
 * `onDelete: restrict` produces, which names the entity, the count and the edge. A
 * uniqueness failure is the same kind of fact and deserves the same kind of answer, so
 * it becomes a Violation with a field path and joins whatever else the commit found
 * wrong.
 *
 * **The index is still the guarantee.** This runs before the transaction opens, so two
 * concurrent creates can both pass it and one will still fail on the constraint. That
 * is the right division: the check makes the ordinary case a good error, and the
 * database keeps being right about the race.
 */
final readonly class UniquenessCheck
{
    /**
     * @param array<string, list<string>> $fields Entity => fields carrying `unique:`.
     */
    public function __construct(
        private StorageAdaptor $storage,
        private ValueEncoder $encoder,
        private array $fields = [],
    ) {
    }

    /**
     * @return list<FieldViolation>
     */
    public function check(Mutation $mutation): array
    {
        $entity = $mutation->entity();
        $changes = $mutation->changes();
        $violations = [];

        foreach ($this->fields[$entity] ?? [] as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }

            // NULL never collides with NULL in SQL, and a nullable unique column is
            // how "at most one, if any" is spelled. Checking it would forbid the
            // second row with no barcode.
            $value = $this->encoder->encode($entity, $field, $changes[$field]);

            if (null === $value) {
                continue;
            }

            if ($this->isTaken($mutation, $field, $value)) {
                $violations[] = new FieldViolation($entity, $field, new Violation(
                    'field.unique',
                    'Another row already holds this value, and the field is unique.',
                ));
            }
        }

        return $violations;
    }

    private function isTaken(Mutation $mutation, string $field, string|int|float|bool $value): bool
    {
        $criteria = (new Criteria($mutation->entity()))
            ->where(new Filter($field, Comparison::Equals, $value));

        $target = $mutation->target();

        // On update the row itself holds the value, and colliding with yourself is not
        // a collision.
        if ($target instanceof EntityId) {
            $criteria = $criteria->where(new Filter('id', Comparison::NotEquals, $target->raw()));
        }

        return $this->storage->count($criteria) > 0;
    }
}
