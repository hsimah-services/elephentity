<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

final readonly class ActionDefinition implements Contributed
{
    /**
     * @param array<string, ArgumentDefinition> $arguments
     */
    public function __construct(
        public string $name,
        public ActionWrites $writes,
        public Origin $origin,
        public array $arguments = [],
        public ?string $description = null,
    ) {
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
