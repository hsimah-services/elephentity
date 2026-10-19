<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Ir;

/**
 * A pattern that opted into generating a shared interface and mutator trait for its
 * own fields and edges.
 *
 * Carried on Schema only for patterns that asked for it and are actually used by at
 * least one entity. The fields and edges are the pattern's own declaration, not a
 * projection of any one entity that uses it — every entity applying a pattern gets
 * exactly this shape.
 */
final readonly class PatternDeclaration
{
    /**
     * @param array<string, FieldDefinition> $fields
     * @param array<string, EdgeDefinition>  $edges
    * @param array<string, PolicyDefinition> $readPolicies
    * @param array<string, PolicyDefinition> $writePolicies
     */
    public function __construct(
        public string $name,
        public array $fields = [],
        public array $edges = [],
        public array $readPolicies = [],
        public array $writePolicies = [],
    ) {
    }
}
