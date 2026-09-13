<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

use Eleph\Runtime\Mutation\MutationContext;

final readonly class PendingWrite implements WriteContext
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        private string $entity,
        private WriteOperation $operation,
        private ?string $action,
        private array $arguments,
        private ?MutationContext $mutation,
    ) {
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function operation(): WriteOperation
    {
        return $this->operation;
    }

    public function action(): ?string
    {
        return $this->action;
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

    public function mutation(): ?MutationContext
    {
        return $this->mutation;
    }
}
