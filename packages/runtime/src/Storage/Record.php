<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

use PheFr\Runtime\Identity\EntityId;

/**
 * One stored row, in primitive form.
 *
 * Values here are what the backend holds — the TIn side of a type's processors. Read
 * processors turn them into domain values on the way up; write processors turn domain
 * values back into these on the way down. The adaptor never sees a domain type.
 */
final readonly class Record
{
    /**
     * @param array<string, scalar|null> $values Keyed by field name.
     */
    public function __construct(
        public string $entity,
        public EntityId $id,
        public array $values,
    ) {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }

    public function value(string $field): string|int|float|bool|null
    {
        return $this->values[$field] ?? null;
    }
}
