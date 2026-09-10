<?php

declare(strict_types=1);

namespace Eleph\WordPress\Taxonomy;

/**
 * Where one edge to a taxonomy-backed entity lives: not a column or a join table, but
 * a row in WordPress's own `wp_term_relationships`.
 *
 * A mirror of `EdgePlacement` for the one relation SQL placement never sees. Derived
 * once, at build time, so the schema builder (which skips it entirely — there is no
 * table to alter) and the adaptor (which reads and writes it) cannot disagree about
 * which edges this applies to.
 */
final readonly class TaxonomyPlacement
{
    public function __construct(
        /** The entity declaring the edge — the one whose rows carry terms. */
        public string $entity,
        public string $edge,
        /** The taxonomy-backed entity this edge points at. */
        public string $target,
        /** The `register_taxonomy()` slug, from the target's own storage handle. */
        public string $taxonomy,
    ) {
    }
}
