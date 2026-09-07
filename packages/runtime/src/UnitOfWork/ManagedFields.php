<?php

declare(strict_types=1);

namespace Eleph\Runtime\UnitOfWork;

use DateTimeImmutable;
use Eleph\Runtime\Mutation\Managed;
use Eleph\Runtime\Mutation\Mutation;

/**
 * The fields the framework fills, and the stamping of them.
 *
 * Runs before verification, not after, and that ordering is the whole point: a managed
 * field that arrived at the required check unset would be reported as missing, when in
 * fact nobody was ever supposed to supply it. Stamp first and every later stage sees a
 * complete row.
 *
 * One instant for the whole commit, passed in rather than read here, so a row created
 * and immediately touched carries the same createdAt and updatedAt — two clock reads
 * would differ by microseconds and make "never modified" impossible to test for.
 */
final readonly class ManagedFields
{
    /**
     * @param array<string, Managed> $policies "Entity.field" => when it is filled.
     */
    public function __construct(private array $policies = [])
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->policies;
    }

    /**
     * Whether a field is the framework's to fill.
     */
    public function has(string $entity, string $field): bool
    {
        return isset($this->policies[$entity . '.' . $field]);
    }

    /**
     * Fill this mutation's managed fields.
     *
     * Deliberately not called for a mutation that changes nothing: bumping `updatedAt`
     * on an entity nobody touched would turn every read-modify-nothing into a write.
     */
    public function stamp(Mutation $mutation, DateTimeImmutable $now): void
    {
        $prefix = $mutation->entity() . '.';

        foreach ($this->policies as $key => $policy) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            if (Managed::Created === $policy && !$mutation->isCreate()) {
                continue;
            }

            $mutation->set(substr($key, strlen($prefix)), $now);
        }
    }
}
