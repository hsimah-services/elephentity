<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\Verification\EntityVerifiers;
use Eleph\Runtime\Verification\FieldViolation;
use Eleph\Runtime\Verification\Verification;
use Eleph\Runtime\Verification\Violation;

/**
 * Runs verification over a mutation and collects everything that failed.
 *
 * Presence comes first, because it is about the mutation rather than a value: a create
 * missing a required field is rejected here, before any SQL runs. Without it the
 * omission reaches the database and the outcome depends on the installation — a strict
 * MySQL raises an error, and WordPress's default session quietly stores
 * `0000-00-00 00:00:00` instead.
 *
 * Then the two value tiers. The field verifier runs first — it is entity-specific and
 * exactly typed — and then the shared type processor. **Both run even when the first
 * fails**, so violations from the two tiers arrive together rather than the second
 * being discovered only after the first is fixed.
 *
 * Nothing throws. The unit of work decides what to do with a non-empty result.
 */
final readonly class VerificationPipeline
{
    /**
     * @param array<string, EntityVerifiers> $verifiers   Keyed by entity name.
     * @param array<string, string>          $fieldTypes  "Entity.field" => declared type name.
     * @param array<string, list<string>>    $required    Entity => fields that must be supplied on create.
     */
    public function __construct(
        private array $verifiers,
        private array $fieldTypes,
        private ProcessorRegistry $processors,
        private array $required = [],
    ) {
    }

    /**
     * @return list<FieldViolation>
     */
    public function verify(Mutation $mutation): array
    {
        $violations = $this->verifyPresence($mutation);

        foreach ($mutation->changes() as $field => $value) {
            foreach ($this->verifyField($mutation, $field, $value) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Required fields, on create only.
     *
     * `required` describes creating a row and nothing else — an update naming three
     * fields is a partial update, and demanding the rest would make partial updates
     * impossible. A key present but null counts as missing: the caller said the field
     * was there and it holds nothing.
     *
     * @return list<FieldViolation>
     */
    private function verifyPresence(Mutation $mutation): array
    {
        if (!$mutation->isCreate()) {
            return [];
        }

        $violations = [];
        $changes = $mutation->changes();

        foreach ($this->required[$mutation->entity()] ?? [] as $field) {
            if (array_key_exists($field, $changes) && null !== $changes[$field]) {
                continue;
            }

            $violations[] = new FieldViolation($mutation->entity(), $field, new Violation(
                'field.required',
                'Required on create, and no value was supplied.',
            ));
        }

        return $violations;
    }

    /**
     * @return list<FieldViolation>
     */
    private function verifyField(Mutation $mutation, string $field, mixed $value): array
    {
        // A null in a nullable field short-circuits: no processor should have to open
        // with the same null check, and there is nothing to convert.
        if (null === $value) {
            return [];
        }

        $result = $this->fieldTier($mutation, $field, $value)
            ->merge($this->typeTier($mutation, $field, $value));

        $violations = [];

        foreach ($result->violations as $violation) {
            $violations[] = new FieldViolation($mutation->entity(), $field, $violation);
        }

        return $violations;
    }

    private function fieldTier(Mutation $mutation, string $field, mixed $value): Verification
    {
        $verifiers = $this->verifiers[$mutation->entity()] ?? null;

        if (null === $verifiers || !in_array($field, $verifiers->verifiedFields(), true)) {
            return Verification::ok();
        }

        return $verifiers->verify($field, $value, $mutation);
    }

    private function typeTier(Mutation $mutation, string $field, mixed $value): Verification
    {
        $type = $this->fieldTypes[$mutation->entity() . '.' . $field] ?? null;

        if (null === $type || !$this->processors->has($type)) {
            return Verification::ok();
        }

        return $this->processors->write($type)->verify($value, $mutation);
    }
}
