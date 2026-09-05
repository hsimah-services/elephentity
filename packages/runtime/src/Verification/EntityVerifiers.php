<?php

declare(strict_types=1);

namespace PheFr\Runtime\Verification;

use PheFr\Runtime\Mutation\MutationContext;

/**
 * The bridge between the runtime and one entity's exactly-typed field verifiers.
 *
 * A generated `PostPriceVerifier` takes `(Money, PostMutationContext)`, which cannot be
 * called through any shared interface — PHP forbids narrowing a parameter type, so no
 * common base could declare it. The generator therefore emits an implementation of
 * this per entity, which narrows the value and wraps the context before dispatching.
 *
 * Entities with no verified fields still get one; it simply returns ok().
 */
interface EntityVerifiers
{
    public function verify(string $field, mixed $value, MutationContext $context): Verification;

    /**
     * Fields this entity verifies, so the pipeline can skip the rest.
     *
     * @return list<string>
     */
    public function verifiedFields(): array;
}
