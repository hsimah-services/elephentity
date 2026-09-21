<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

/**
 * Canonical mapping-key order. Section members retain declaration order, which is semantic for
 * sideEffects.
 */
final readonly class CanonicalOrder
{
    /** @var array<string, list<string>> */
    private const ORDERS = [
        'project' => ['project', 'description', 'storage', 'integrations'],
        'projectStorage' => ['driver', 'tablePrefix'],
        'entity' => ['entity', 'description', 'use', 'configure', 'integrations', 'storage', 'policies', 'fields', 'edges', 'queries', 'actions', 'sideEffects', 'readPolicies', 'writePolicies'],
        'pattern' => ['pattern', 'description', 'requires', 'config', 'use', 'storage', 'fields', 'edges', 'queries', 'actions', 'sideEffects', 'readPolicies', 'writePolicies'],
        'type' => ['type', 'description', 'primitive', 'processors', 'values'],
        'storage' => ['table', 'handle'],
        'requires' => ['driver'],
        'field' => ['type', 'description', 'required', 'nullable', 'default', 'unique', 'indexed', 'immutable', 'managed', 'maxLength', 'values', 'verify'],
        'edge' => ['to', 'cardinality', 'description', 'inverse', 'onDelete'],
        'inverse' => ['name', 'unique'],
        'query' => ['description', 'args', 'returns', 'integrations', 'handler'],
        'returns' => ['type', 'cardinality'],
        'action' => ['description', 'args', 'writes', 'handler'],
        'writes' => ['fields', 'edges'],
        'sideEffect' => ['description', 'on', 'phase', 'handler'],
        'policy' => ['description', 'handler'],
        'terminalRule' => ['read', 'write'],
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
