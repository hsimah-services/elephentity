<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

use Eleph\Schema\Ir\ActionDefinition;
use Eleph\Schema\Ir\ActionWrites;
use Eleph\Schema\Ir\ArgumentDefinition;
use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EdgeInverse;
use Eleph\Schema\Ir\EnumSource;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\OnDelete;
use Eleph\Schema\Ir\Origin;
use Eleph\Schema\Ir\QueryDefinition;
use Eleph\Schema\Ir\ReturnDefinition;
use Eleph\Schema\Ir\TriggerDefinition;
use Eleph\Schema\Ir\TriggerEvent;
use Eleph\Schema\Ir\TriggerPhase;
use Eleph\Schema\Ir\TypeReference;

/**
 * Turns a validated spec document into IR sections.
 *
 * Everything here assumes the JSON Schema has already passed, so shapes are trusted
 * and enum values are known to be members. Meaning — does this type exist, does this
 * edge target resolve — is checked later.
 */
final readonly class SectionParser
{
    public function parse(SpecReader $reader, Origin $origin): ParsedSections
    {
        return new ParsedSections(
            $this->fields($reader, $origin),
            $this->edges($reader, $origin),
            $this->queries($reader, $origin),
            $this->actions($reader, $origin),
            $this->triggers($reader, $origin),
        );
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function fields(SpecReader $reader, Origin $origin): array
    {
        $fields = [];

        foreach ($reader->readers('fields') as $name => $field) {
            $fields[$name] = new FieldDefinition(
                name: $name,
                type: TypeReference::parse($field->string('type')),
                origin: $origin,
                description: $field->optionalString('description'),
                required: $field->bool('required'),
                nullable: $field->bool('nullable'),
                default: $field->raw('default'),
                hasDefault: $field->has('default'),
                unique: $field->bool('unique'),
                indexed: $field->bool('indexed'),
                immutable: $field->bool('immutable'),
                maxLength: $field->optionalInt('maxLength'),
                enum: $this->enumSource($field),
                verify: $field->bool('verify'),
            );
        }

        return $fields;
    }

    private function enumSource(SpecReader $field): ?EnumSource
    {
        if (!$field->has('values')) {
            return null;
        }

        $values = $field->raw('values');

        // The schema permits a string naming a declared enum, or an inline member list.
        return is_string($values)
            ? EnumSource::declared($values)
            : EnumSource::inline($field->stringList('values'));
    }

    /**
     * @return array<string, EdgeDefinition>
     */
    private function edges(SpecReader $reader, Origin $origin): array
    {
        $edges = [];

        foreach ($reader->readers('edges') as $name => $edge) {
            $onDelete = $edge->optionalString('onDelete');

            $edges[$name] = new EdgeDefinition(
                name: $name,
                to: $edge->string('to'),
                cardinality: Cardinality::from($edge->string('cardinality')),
                origin: $origin,
                description: $edge->optionalString('description'),
                inverse: $this->inverse($edge),
                onDelete: null === $onDelete ? OnDelete::Restrict : OnDelete::from($onDelete),
            );
        }

        return $edges;
    }

    private function inverse(SpecReader $edge): ?EdgeInverse
    {
        if (!$edge->has('inverse')) {
            return null;
        }

        $inverse = $edge->raw('inverse');

        if (is_string($inverse)) {
            return EdgeInverse::named($inverse);
        }

        if (!is_array($inverse)) {
            // The remaining permitted form is the literal true.
            return EdgeInverse::derived();
        }

        $reader = $edge->reader('inverse');
        $name = $reader?->optionalString('name');
        $unique = $reader?->bool('unique', true) ?? true;

        return null === $name
            ? EdgeInverse::derived($unique)
            : EdgeInverse::named($name, $unique);
    }

    /**
     * @return array<string, QueryDefinition>
     */
    private function queries(SpecReader $reader, Origin $origin): array
    {
        $queries = [];

        foreach ($reader->readers('queries') as $name => $query) {
            $returns = $query->reader('returns');
            $cardinality = $returns?->optionalString('cardinality');

            $queries[$name] = new QueryDefinition(
                name: $name,
                returns: new ReturnDefinition(
                    type: (string) $returns?->string('type'),
                    cardinality: null === $cardinality
                        ? Cardinality::Many
                        : Cardinality::from($cardinality),
                ),
                origin: $origin,
                arguments: $this->arguments($query),
                description: $query->optionalString('description'),
                // Raw for now: what a given integration accepts is only knowable once
                // the compiler has the registry and the project's enabled set.
                integrations: $this->rawIntegrations($query),
            );
        }

        return $queries;
    }

    /**
     * @return array<string, ActionDefinition>
     */
    private function actions(SpecReader $reader, Origin $origin): array
    {
        $actions = [];

        foreach ($reader->readers('actions') as $name => $action) {
            $writes = $action->reader('writes');

            $actions[$name] = new ActionDefinition(
                name: $name,
                writes: new ActionWrites(
                    fields: $writes?->stringList('fields') ?? [],
                    edges: $writes?->stringList('edges') ?? [],
                ),
                origin: $origin,
                arguments: $this->arguments($action),
                description: $action->optionalString('description'),
            );
        }

        return $actions;
    }

    /**
     * @return array<string, TriggerDefinition>
     */
    private function triggers(SpecReader $reader, Origin $origin): array
    {
        $triggers = [];

        foreach ($reader->readers('triggers') as $name => $trigger) {
            $phase = $trigger->optionalString('phase');

            $events = [];

            foreach ($trigger->stringList('on') as $event) {
                $events[] = TriggerEvent::from($event);
            }

            $triggers[$name] = new TriggerDefinition(
                name: $name,
                events: $events,
                origin: $origin,
                phase: null === $phase
                    ? TriggerPhase::PreCommit
                    : TriggerPhase::from($phase),
                description: $trigger->optionalString('description'),
            );
        }

        return $triggers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rawIntegrations(SpecReader $reader): array
    {
        $raw = [];

        foreach ($reader->reader('integrations')?->all() ?? [] as $name => $settings) {
            /** @var array<string, mixed> $settings */
            $raw[$name] = is_array($settings) ? $settings : [];
        }

        return $raw;
    }

    /**
     * @return array<string, ArgumentDefinition>
     */
    private function arguments(SpecReader $reader): array
    {
        $arguments = [];

        foreach ($reader->readers('args') as $name => $argument) {
            $arguments[$name] = new ArgumentDefinition(
                name: $name,
                type: TypeReference::parse($argument->string('type')),
                nullable: $argument->bool('nullable'),
            );
        }

        return $arguments;
    }
}
