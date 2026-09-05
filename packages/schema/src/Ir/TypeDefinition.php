<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * A declared value type aliasing a primitive, or a declared enum.
 *
 * The spec carries no application namespaces: `processors: true` generates the
 * ReadProcessor and WriteProcessor interfaces and boot fails until both are
 * implemented, exactly as with handlers and field verifiers. The value class itself
 * is user-owned — a value object has behaviour no generator can invent.
 */
final readonly class TypeDefinition
{
    /**
     * @param list<string>|null $values Members, when this type is a declared enum.
     */
    public function __construct(
        public string $name,
        public Primitive $primitive,
        public string $sourceFile,
        public ?string $description = null,
        public bool $hasProcessors = false,
        public ?array $values = null,
    ) {
    }

    public function isEnum(): bool
    {
        return null !== $this->values;
    }
}
