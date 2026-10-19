<?php

declare(strict_types=1);

namespace App\Patterns\HasTimestamps;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Eleph\Runtime\Mutation\MutableMutationContext;

/**
 * Extend with an application adapter implementing the generated side-effect interfaces.
 * Delete exclusion belongs to the spec: a mutation context has no delete flag.
 */
abstract class HasTimestampsTrigger
{
    /** @param null|Closure(): DateTimeImmutable $clock Defaults to the current UTC time. */
    public function __construct(private readonly ?Closure $clock = null)
    {
    }

    final public function handle(MutableMutationContext $context): void
    {
        if (!$context->isCreate() && $context->isChanged('createdAt')) {
            throw new DomainException('createdAt cannot be changed after creation.');
        }

        $now = $this->now();
        if ($context->isCreate()) {
            $context->set('createdAt', $now);
        }
        $context->set('updatedAt', $now);
    }

    private function now(): DateTimeImmutable
    {
        return null === $this->clock
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : ($this->clock)();
    }
}
