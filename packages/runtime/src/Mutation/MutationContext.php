<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

use Eleph\Runtime\Identity\Identifier;

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

    /**
     * What this mutation will attach this edge to.
     *
     * This is the write side's own bookkeeping, not the row's true final state: there
     * is no original edge state to fall back to (nothing here loads what is currently
     * attached), so on an update an edge this mutation never touches reads as empty
     * even though the row may already hold something. On create that distinction does
     * not exist — nothing is attached yet — which is exactly the case a cross-edge
     * rule such as "exactly one of these three is set" needs.
     *
     * @return list<Identifier>
     */
    public function pendingEdge(string $edge): array;

    /**
     * Whether this mutation's buffer recorded anything for this edge — an add, a
     * remove, or a wholesale replacement. False does not mean the edge is empty; it
     * means this mutation is silent about it.
     */
    public function isEdgeChanged(string $edge): bool;
}
