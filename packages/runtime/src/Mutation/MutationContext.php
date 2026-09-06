<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

/**
 * The state a verifier is allowed to reason about.
 *
 * Holds the entity as it was when the mutation started, plus the pending changes, so a
 * verifier can compare a value against the original or against another field — the
 * cross-field rules a per-value processor could not otherwise express.
 *
 * Every verifier runs against the same final pending state rather than incrementally
 * as setters fire, so ordering does not matter and two fields can validate against
 * each other symmetrically.
 *
 * Values are domain-typed (TOut) on both sides: write() has not run at verify time,
 * and originals come from the hydrated entity.
 *
 * Access is stringly-typed here because a shared processor such as Money is used by
 * many entities and cannot accept a generated per-entity context. Rules that want
 * exact types belong in a field verifier, whose generated interface names both the
 * entity and the field's domain type.
 */
interface MutationContext
{
    public function entity(): string;

    /**
     * True when there is no prior state, so every original() is null.
     */
    public function isCreate(): bool;

    /**
     * The value as it was when the mutation started; null on create.
     */
    public function original(string $field): mixed;

    /**
     * The value this mutation will write, falling back to the original when the field
     * is untouched — so a verifier always sees what the row will actually hold.
     */
    public function pending(string $field): mixed;

    public function isChanged(string $field): bool;

    /**
     * Only the fields this mutation touches, in domain form.
     *
     * @return array<string, mixed>
     */
    public function changes(): array;
}
