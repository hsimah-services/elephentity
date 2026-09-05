<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * Where an enum field's members come from.
 *
 * Inline members generate a class named from entity + field; a declared type
 * references types/<Name>.yml. Both forms generate the same fully-qualified name, so
 * promoting an inline enum to a file is a no-op in the generated code.
 */
final readonly class EnumSource
{
    /**
     * @param list<string>|null $inlineValues
     */
    private function __construct(
        public ?array $inlineValues,
        public ?string $declaredType,
    ) {
    }

    /**
     * @param list<string> $values
     */
    public static function inline(array $values): self
    {
        return new self($values, null);
    }

    public static function declared(string $typeName): self
    {
        return new self(null, $typeName);
    }

    public function isInline(): bool
    {
        return null !== $this->inlineValues;
    }
}
