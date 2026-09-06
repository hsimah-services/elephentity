<?php

declare(strict_types=1);

namespace Eleph\Runtime\Storage;

/**
 * One thing that must happen to dependents when an entity is deleted.
 *
 * Derived from an edge at build time, because the runtime has no schema: the generator
 * knows which edges point at what, and emits the answer rather than making the unit of
 * work work it out.
 */
final readonly class DeletionRule
{
    public function __construct(
        /** The entity whose rows depend on the one being deleted. */
        public string $dependent,
        /** The edge these rows are reached through. */
        public string $edge,
        /** The entity that declares that edge. */
        public string $declaredBy,
        public DeletionPolicy $policy,
        /**
         * Whether the link lives in a join table.
         *
         * Join rows always go, whatever the policy — a link to a row that no longer
         * exists is not a policy choice. `Cascade` additionally deletes the far side,
         * which for shared vocabulary is rarely what anyone wants and is exactly why
         * it has to be asked for.
         */
        public bool $viaJoinTable = false,
    ) {
    }
}
