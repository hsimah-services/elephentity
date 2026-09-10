<?php

declare(strict_types=1);

namespace Eleph\WordPress\Taxonomy;

use RuntimeException;
use WP_Error;
use WP_Term;

/**
 * The only class that calls `wp_insert_term()` and its siblings.
 *
 * As thin as `WpdbDatabase`: argument mapping and `WP_Error` handling, nothing a test
 * without a WordPress installation could usefully exercise. `TaxonomyStorage` is where
 * the actual decisions live.
 */
final readonly class WordPressTerms implements Terms
{
    public function insert(string $taxonomy, string $name): int
    {
        /** @var array{term_id: int, term_taxonomy_id: int}|WP_Error $result */
        $result = wp_insert_term($name, $taxonomy);

        return $this->id($result, $taxonomy, $name);
    }

    public function update(string $taxonomy, int $termId, string $name): void
    {
        /** @var array{term_id: int, term_taxonomy_id: int}|WP_Error $result */
        $result = wp_update_term($termId, $taxonomy, ['name' => $name]);

        $this->guard($result, $taxonomy);
    }

    public function delete(string $taxonomy, int $termId): void
    {
        // The schema compiler has already rejected an empty handle.
        // @phpstan-ignore argument.type
        $result = wp_delete_term($termId, $taxonomy);

        if ($result instanceof WP_Error) {
            throw new RuntimeException(sprintf('Could not delete term %d in %s: %s', $termId, $taxonomy, $result->get_error_message()));
        }
    }

    public function name(string $taxonomy, int $termId): ?string
    {
        $term = get_term($termId, $taxonomy);

        return $term instanceof WP_Term ? $term->name : null;
    }

    public function names(string $taxonomy, array $termIds): array
    {
        if ([] === $termIds) {
            return [];
        }

        /** @var list<WP_Term>|WP_Error $terms */
        $terms = get_terms(['taxonomy' => $taxonomy, 'include' => $termIds, 'hide_empty' => false]);

        $names = [];

        foreach ($this->guard($terms, $taxonomy) as $term) {
            $names[$term->term_id] = $term->name;
        }

        return $names;
    }

    public function list(string $taxonomy, ?int $limit, int $offset): array
    {
        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'orderby' => 'name',
            'order' => 'ASC',
            'offset' => $offset,
        ];

        if (null !== $limit) {
            $args['number'] = $limit;
        }

        /** @var list<int>|WP_Error $ids */
        $ids = get_terms($args);

        return array_map(intval(...), $this->guard($ids, $taxonomy));
    }

    public function count(string $taxonomy): int
    {
        $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        if ($count instanceof WP_Error) {
            throw new RuntimeException(sprintf('Could not count terms in %s: %s', $taxonomy, $count->get_error_message()));
        }

        return (int) $count;
    }

    public function termsOfMany(string $taxonomy, array $objectIds): array
    {
        if ([] === $objectIds) {
            return [];
        }

        // 'all_with_object_id' is the one fields mode that annotates each term with
        // which object it came from — without it, terms shared by two objects in the
        // same call are indistinguishable from each other.
        /** @var list<WP_Term&object{object_id: int}>|WP_Error $terms */
        $terms = wp_get_object_terms($objectIds, $taxonomy, ['fields' => 'all_with_object_id']);

        $byObject = [];

        foreach ($this->guard($terms, $taxonomy) as $term) {
            $byObject[$term->object_id][] = (int) $term->term_id;
        }

        return $byObject;
    }

    public function setTerms(string $taxonomy, int $objectId, array $termIds, bool $append): void
    {
        $result = wp_set_object_terms($objectId, $termIds, $taxonomy, $append);

        if ($result instanceof WP_Error) {
            throw new RuntimeException(sprintf('Could not set %s terms on %d: %s', $taxonomy, $objectId, $result->get_error_message()));
        }
    }

    public function removeTerm(string $taxonomy, int $objectId, int $termId): void
    {
        $result = wp_remove_object_terms($objectId, $termId, $taxonomy);

        if ($result instanceof WP_Error) {
            throw new RuntimeException(sprintf('Could not remove a %s term from %d: %s', $taxonomy, $objectId, $result->get_error_message()));
        }
    }

    public function clearTerms(string $taxonomy, int $objectId): void
    {
        $result = wp_set_object_terms($objectId, [], $taxonomy, false);

        if ($result instanceof WP_Error) {
            throw new RuntimeException(sprintf('Could not clear %s terms on %d: %s', $taxonomy, $objectId, $result->get_error_message()));
        }
    }

    /**
     * @template T
     *
     * @param T|WP_Error $result
     *
     * @return T
     */
    private function guard(mixed $result, string $taxonomy): mixed
    {
        if ($result instanceof WP_Error) {
            throw new RuntimeException(sprintf('Taxonomy "%s" call failed: %s', $taxonomy, $result->get_error_message()));
        }

        return $result;
    }

    /**
     * @param array{term_id: int, term_taxonomy_id: int}|WP_Error $result
     */
    private function id(mixed $result, string $taxonomy, string $name): int
    {
        if ($result instanceof WP_Error) {
            throw new RuntimeException(sprintf('Could not insert "%s" into %s: %s', $name, $taxonomy, $result->get_error_message()));
        }

        return $result['term_id'];
    }
}
