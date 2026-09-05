<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

final readonly class FieldDefinition implements Contributed
{
    public function __construct(
        public string $name,
        public TypeReference $type,
        public Origin $origin,
        public ?string $description = null,
        /** Must be supplied on create. Distinct from nullable. */
        public bool $required = false,
        /** The column admits NULL. Distinct from required. */
        public bool $nullable = false,
        public mixed $default = null,
        public bool $hasDefault = false,
        public bool $unique = false,
        public bool $indexed = false,
        /** Write-once: settable on create, no setter afterwards. */
        public bool $immutable = false,
        /** VARCHAR width for string fields; null means the default applies. */
        public ?int $maxLength = null,
        public ?EnumSource $enum = null,
        /** Generate an entity-specific verifier interface for this field. */
        public bool $verify = false,
    ) {
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
