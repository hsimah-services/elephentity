<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\Verification\EntityVerifiers;
use Eleph\Runtime\Verification\FieldViolation;
use Eleph\Runtime\Verification\Verification;

/**
 * Runs both tiers of verification over a mutation and collects everything that failed.
 *
 * The field verifier runs first — it is entity-specific and exactly typed — and then
 * the shared type processor. **Both run even when the first fails**, so violations from
 * the two tiers arrive together rather than the second being discovered only after the
 * first is fixed.
 *
 * Nothing throws. The unit of work decides what to do with a non-empty result.
 */
final readonly class VerificationPipeline
{
    /**
     * @param array<string, EntityVerifiers> $verifiers   Keyed by entity name.
     * @param array<string, string>          $fieldTypes  "Entity.field" => declared type name.
     */
    public function __construct(
        private array $verifiers,
        private array $fieldTypes,
        private ProcessorRegistry $processors,
    ) {
    }

    /**
     * @return list<FieldViolation>
     */
    public function verify(Mutation $mutation): array
    {
        $violations = [];

        foreach ($mutation->changes() as $field => $value) {
            foreach ($this->verifyField($mutation, $field, $value) as $violation) {
                $violations[] = $violation;
            }
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
