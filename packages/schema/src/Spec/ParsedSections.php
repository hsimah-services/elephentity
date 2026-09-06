<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

use Eleph\Schema\Ir\ActionDefinition;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\QueryDefinition;
use Eleph\Schema\Ir\TriggerDefinition;

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
