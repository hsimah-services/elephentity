<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

final readonly class PolicyDefinition implements Contributed
{
    public function __construct(
        public string $name,
        public Origin $origin,
        public ?string $description = null,
    ) {
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
