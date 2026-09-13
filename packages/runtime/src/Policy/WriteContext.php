<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

use Eleph\Runtime\Mutation\MutationContext;

interface WriteContext
{
    public function entity(): string;

    public function operation(): WriteOperation;

    public function action(): ?string;

    /** @return array<string, mixed> */
    public function arguments(): array;

    public function mutation(): ?MutationContext;
}
