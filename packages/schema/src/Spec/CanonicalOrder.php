<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

/**
 * The order keys are written in, everywhere.
 *
 * The spec is the entity's changelog, so a diff should show what changed and nothing
 * else. Two authors — or the same LLM on two different days — will otherwise write the
 * same field with its keys in a different order, and every review starts by deciding
 * which lines matter.
 *
 * Ordering applies to the keys of a mapping, never to the members of a section.
 * Declaration order is *semantic* for triggers, which run in the order the spec lists
 * them, and meaningful to a reader everywhere else. Sorting members would quietly
 * change behaviour.
 */
final readonly class CanonicalOrder
{
    /** @var array<string, list<string>> */
    private const ORDERS = [
        'project' => ['project', 'description', 'storage'],
        'projectStorage' => ['driver', 'tablePrefix'],
        'entity' => ['entity', 'description', 'use', 'configure', 'storage', 'fields', 'edges', 'queries', 'actions', 'triggers'],
        'pattern' => ['pattern', 'description', 'requires', 'config', 'use', 'storage', 'fields', 'edges', 'queries', 'actions', 'triggers'],
        'type' => ['type', 'description', 'primitive', 'processors', 'values'],
        'storage' => ['table', 'handle'],
        'requires' => ['driver'],
        'field' => ['type', 'description', 'required', 'nullable', 'default', 'unique', 'indexed', 'immutable', 'maxLength', 'values', 'verify'],
        'edge' => ['to', 'cardinality', 'description', 'inverse', 'onDelete'],
        'inverse' => ['name', 'unique'],
        'query' => ['description', 'args', 'returns', 'handler'],
        'returns' => ['type', 'cardinality'],
        'action' => ['description', 'args', 'writes', 'handler'],
        'writes' => ['fields', 'edges'],
        'trigger' => ['description', 'on', 'phase', 'handler'],
        'argument' => ['type', 'nullable'],
        'configParameter' => ['type', 'description', 'of', 'values', 'nullable', 'default'],
    ];

    /**
     * @return list<string>
     */
    public function for(string $shape): array
    {
        return self::ORDERS[$shape] ?? [];
    }

    /**
     * Whether the keys present appear in canonical order.
     *
     * Only relative order matters: a mapping that omits optional keys is still
     * canonical, and an unrecognised key is ignored rather than being an error — the
     * JSON Schema is what rejects those.
     *
     * @param list<string> $keys
     */
    public function isOrdered(string $shape, array $keys): bool
    {
        return $keys === $this->sort($shape, $keys);
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public function sort(string $shape, array $keys): array
    {
        $canonical = $this->for($shape);

        if ([] === $canonical) {
            return $keys;
        }

        $known = [];
        $unknown = [];

        foreach ($canonical as $key) {
            if (in_array($key, $keys, true)) {
                $known[] = $key;
            }
        }

        foreach ($keys as $key) {
            if (!in_array($key, $canonical, true)) {
                $unknown[] = $key;
            }
        }

        return [...$known, ...$unknown];
    }
}
