<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\WordPress\Taxonomy\Terms;

/**
 * An in-memory taxonomy, standing in for WordPress's term tables.
 *
 * `TaxonomyStorage` is the thing worth testing — what it asks for, and how it turns
 * the answer into records — so this fake is deliberately dumb: one array, no WordPress
 * semantics beyond what `TaxonomyStorage` itself relies on.
 */
final class FakeTerms implements Terms
{
    /** @var array<string, array<int, string>> taxonomy => term id => name */
    public array $terms = [];

    /** @var array<string, array<int, list<int>>> taxonomy => object id => term ids */
    public array $relationships = [];

    private int $nextId = 1;

    public function insert(string $taxonomy, string $name): int
    {
        $id = $this->nextId++;
        $this->terms[$taxonomy][$id] = $name;

        return $id;
    }

    public function update(string $taxonomy, int $termId, string $name): void
    {
        $this->terms[$taxonomy][$termId] = $name;
    }

    public function delete(string $taxonomy, int $termId): void
    {
        unset($this->terms[$taxonomy][$termId]);
    }

    public function name(string $taxonomy, int $termId): ?string
    {
        return $this->terms[$taxonomy][$termId] ?? null;
    }

    public function names(string $taxonomy, array $termIds): array
    {
        $names = [];

        foreach ($termIds as $termId) {
            if (isset($this->terms[$taxonomy][$termId])) {
                $names[$termId] = $this->terms[$taxonomy][$termId];
            }
        }

        return $names;
    }

    public function list(string $taxonomy, ?int $limit, int $offset): array
    {
        $ids = array_keys($this->terms[$taxonomy] ?? []);
        sort($ids);

        $sliced = array_slice($ids, $offset, $limit);

        return array_values($sliced);
    }

    public function count(string $taxonomy): int
    {
        return count($this->terms[$taxonomy] ?? []);
    }

    public function termsOfMany(string $taxonomy, array $objectIds): array
    {
        $grouped = [];

        foreach ($objectIds as $objectId) {
            $termIds = $this->relationships[$taxonomy][$objectId] ?? [];

            if ([] !== $termIds) {
                $grouped[$objectId] = $termIds;
            }
        }

        return $grouped;
    }

    public function setTerms(string $taxonomy, int $objectId, array $termIds, bool $append): void
    {
        $existing = $append ? ($this->relationships[$taxonomy][$objectId] ?? []) : [];
        $this->relationships[$taxonomy][$objectId] = array_values(array_unique([...$existing, ...$termIds]));
    }

    public function removeTerm(string $taxonomy, int $objectId, int $termId): void
    {
        $this->relationships[$taxonomy][$objectId] = array_values(array_diff(
            $this->relationships[$taxonomy][$objectId] ?? [],
            [$termId],
        ));
    }

    public function clearTerms(string $taxonomy, int $objectId): void
    {
        unset($this->relationships[$taxonomy][$objectId]);
    }
}
