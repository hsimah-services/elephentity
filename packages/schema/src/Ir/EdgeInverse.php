<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * The reverse accessor generated on the far side of an edge.
 *
 * `unique` describes the reverse side: true means the target points back at exactly
 * one of this entity, false means many. Together with the forward cardinality it
 * determines the relation, and therefore where the key lives.
 *
 * A derived name is the source entity lowercased — singular — so it is only legal when
 * the reverse is to-one. The generator never pluralises: English inflection quietly
 * produces "Categorys" and libraries disagree with each other, which is unacceptable
 * in a framework whose selling point is predictable output.
 */
final readonly class EdgeInverse
{
    private function __construct(
        public bool $derived,
        public ?string $name,
        public bool $unique,
    ) {
    }

    public static function derived(bool $unique = true): self
    {
        return new self(true, null, $unique);
    }

    public static function named(string $name, bool $unique = true): self
    {
        return new self(false, $name, $unique);
    }

    /** The accessor name on the target entity, given the entity declaring the edge. */
    public function nameFor(string $declaringEntity): string
    {
        return $this->derived ? lcfirst($declaringEntity) : (string) $this->name;
    }
}
