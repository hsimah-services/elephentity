<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

/** One requested action, retained throughout the mutation lifecycle. */
final readonly class ActionCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(public string $name, public array $arguments = [])
    {
    }
}
