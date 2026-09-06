<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * A field or argument's declared type: either a primitive or a declared value type.
 */
final readonly class TypeReference
{
    private function __construct(
        public ?Primitive $primitive,
        public ?string $declaredType,
    ) {
    }

    public static function primitive(Primitive $primitive): self
    {
        return new self($primitive, null);
    }

    public static function declared(string $name): self
    {
        return new self(null, $name);
    }

    /**
     * Resolve a raw spec string, which is a primitive if it names one and a declared
     * type otherwise. Whether that declared type exists is a semantic check.
     */
    public static function parse(string $raw): self
    {
        $primitive = Primitive::tryFrom($raw);

        return null === $primitive ? self::declared($raw) : self::primitive($primitive);
    }

    public function isPrimitive(): bool
    {
        return null !== $this->primitive;
    }

    public function name(): string
    {
        return $this->primitive->value ?? (string) $this->declaredType;
    }
}
