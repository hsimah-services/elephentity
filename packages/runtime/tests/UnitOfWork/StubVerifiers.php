<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Runtime\Verification\EntityVerifiers;
use Eleph\Runtime\Verification\Verification;

/**
 * A stand-in for the generated verifier bridge.
 */
final class StubVerifiers implements EntityVerifiers
{
    /**
     * @param array<string, Verification> $results Keyed by field.
     */
    public function __construct(private readonly array $results)
    {
    }

    public function verify(string $field, mixed $value, MutationContext $context): Verification
    {
        return $this->results[$field] ?? Verification::ok();
    }

    public function verifiedFields(): array
    {
        return array_keys($this->results);
    }
}
