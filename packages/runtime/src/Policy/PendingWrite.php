<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

use Eleph\Runtime\Mutation\MutationContext;

final readonly class PendingWrite implements WriteContext
{
    /** @param array<string, mixed> $arguments */
    private function __construct(
        private string $entity,
        private WriteOperation $operation,
        private ?string $action,
        private array $arguments,
        private ?MutationContext $mutation,
    ) {
    }

    public static function create(string $entity, MutationContext $mutation): self
    {
        return new self($entity, WriteOperation::Create, null, [], $mutation);
    }

    public static function update(string $entity, MutationContext $mutation): self
    {
        return new self($entity, WriteOperation::Update, null, [], $mutation);
    }

    public static function delete(string $entity): self
    {
        return new self($entity, WriteOperation::Delete, null, [], null);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function forAction(string $entity, string $action, array $arguments, MutationContext $mutation): self
    {
        return new self($entity, WriteOperation::Action, $action, $arguments, $mutation);
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
