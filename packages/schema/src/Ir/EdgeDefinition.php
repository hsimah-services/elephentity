<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

final readonly class EdgeDefinition implements Contributed
{
    public function __construct(
        public string $name,
        public string $to,
        public Cardinality $cardinality,
        public Origin $origin,
        public ?string $description = null,
        public ?EdgeInverse $inverse = null,
        public OnDelete $onDelete = OnDelete::Restrict,
        /** Must be set on create. Checked at commit; never a NOT NULL column. */
        public bool $required = false,
    ) {
    }

    /**
     * An edge with no declared inverse still has a shape. Absent an explicit statement
     * the reverse is treated as non-unique, which is the conservative reading: it
     * assumes less about the target than claiming uniqueness would.
     */
    public function relation(): RelationKind
    {
        return RelationKind::of($this->cardinality, $this->inverse->unique ?? false);
    }

    public function declaredIn(): Origin
    {
        return $this->origin;
    }
}
