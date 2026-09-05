<?php

declare(strict_types=1);

namespace PheFr\Schema\Spec;

use PheFr\Schema\Ir\ActionDefinition;
use PheFr\Schema\Ir\EdgeDefinition;
use PheFr\Schema\Ir\FieldDefinition;
use PheFr\Schema\Ir\QueryDefinition;
use PheFr\Schema\Ir\TriggerDefinition;

/**
 * The declarable sections of a spec, parsed but not yet merged.
 *
 * Entities and patterns produce the same shape, because a pattern is a fragment of an
 * entity spec — any section an entity may declare, a pattern may declare.
 */
final readonly class ParsedSections
{
    /**
     * @param array<string, FieldDefinition>   $fields
     * @param array<string, EdgeDefinition>    $edges
     * @param array<string, QueryDefinition>   $queries
     * @param array<string, ActionDefinition>  $actions
     * @param array<string, TriggerDefinition> $triggers
     */
    public function __construct(
        public array $fields = [],
        public array $edges = [],
        public array $queries = [],
        public array $actions = [],
        public array $triggers = [],
    ) {
    }
}
