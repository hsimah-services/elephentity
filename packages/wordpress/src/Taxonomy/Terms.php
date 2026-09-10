<?php

declare(strict_types=1);

namespace Eleph\WordPress\Taxonomy;

/**
 * The narrow slice of WordPress's term API this adaptor uses.
 *
 * An interface rather than reaching for `wp_insert_term()` and friends directly, for
 * the same reason `Database` exists: `TaxonomyStorage` stays testable without a
 * WordPress installation, and everything WordPress-shaped about terms — `WP_Error`
 * returns, `wp_terms`/`wp_term_relationships` themselves — is confined to one small,
 * untested class.
 */
interface Terms
{
    /**
     * @return int The new term's id.
     */
    public function insert(string $taxonomy, string $name): int;

    public function update(string $taxonomy, int $termId, string $name): void;

    public function delete(string $taxonomy, int $termId): void;

    /**
     * Null when no such term exists.
     */
    public function name(string $taxonomy, int $termId): ?string;

    /**
     * @param list<int> $termIds
     *
     * @return array<int, string> term id => name, for whichever ids exist.
     */
    public function names(string $taxonomy, array $termIds): array;

    /**
     * Every term in the taxonomy, ordered by name, id-only — callers page and hydrate
     * through `names()` the same as any other list.
     *
     * @return list<int>
     */
    public function list(string $taxonomy, ?int $limit, int $offset): array;

    public function count(string $taxonomy): int;

    /**
     * The terms attached to each of several objects along this taxonomy, in one call —
     * the batching primitive an edge loader needs to resolve fifty objects' terms
     * without fifty round trips.
     *
     * @param list<int> $objectIds
     *
     * @return array<int, list<int>> object id => term ids. An id with no terms is absent.
     */
    public function termsOfMany(string $taxonomy, array $objectIds): array;

    /**
     * Replace wholesale, add, or remove — `TaxonomyStorage` never asks for a read
     * before a write, because WordPress's own `wp_set_object_terms()` already knows
     * how to append rather than replace.
     *
     * @param list<int> $termIds
     */
    public function setTerms(string $taxonomy, int $objectId, array $termIds, bool $append): void;

    public function removeTerm(string $taxonomy, int $objectId, int $termId): void;

    public function clearTerms(string $taxonomy, int $objectId): void;
}
