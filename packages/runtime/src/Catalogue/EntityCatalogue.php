<?php

declare(strict_types=1);

namespace Eleph\Runtime\Catalogue;

use Eleph\Runtime\Mutation\EntityTriggers;
use Eleph\Runtime\Mutation\Managed;
use Eleph\Runtime\Mutation\MutationBuffer;
use Eleph\Runtime\Query\Hydrator;
use Eleph\Runtime\Storage\DeletionRule;
use Eleph\Runtime\Verification\EntityVerifiers;

/**
 * Everything the runtime needs to know about entities, addressed by name.
 *
 * Generated, because all of it is a walk over the spec: which hydrator belongs to which
 * entity, what an edge points at, which field carries a declared type. The runtime has
 * no schema and should not gain one — the IR is a build-time artefact and loading it
 * per request would be paying for compilation twice.
 *
 * Implementations resolve generated classes through the application's container rather
 * than taking dozens of constructor arguments, so adding an entity does not widen a
 * signature.
 */
interface EntityCatalogue
{
    /**
     * @return list<string>
     */
    public function entities(): array;

    /**
     * @return Hydrator<object>
     */
    public function hydrator(string $entity): Hydrator;

    public function verifiers(string $entity): EntityVerifiers;

    public function triggers(string $entity): EntityTriggers;

    /**
     * @return list<DeletionRule>
     */
    public function deletionRules(string $entity): array;

    /**
     * @return array<string, string> "Entity.edge" => target entity
     */
    public function edgeTargets(): array;

    /**
     * @return array<string, string> "Entity.field" => declared type name
     */
    public function fieldTypes(): array;

    /**
     * @return list<string>
     */
    public function fieldNames(string $entity): array;

    /**
     * Fields that must be supplied when the row is created.
     *
     * Exposed because nothing downstream could work it out: the runtime has no schema,
     * so without this `required: true` is a word in the spec that changes nothing.
     * A field with a default is not listed — the column supplies one.
     *
     * @return list<string>
     */
    public function requiredFields(string $entity): array;

    /**
     * Fields carrying a uniqueness constraint, so the commit can check one before the
     * database does and report it as a violation rather than a SQL exception.
     *
     * @return list<string>
     */
    public function uniqueFields(string $entity): array;

    /**
     * Fields the framework fills, and when.
     *
     * @return array<string, Managed> "Entity.field" => policy
     */
    public function managedFields(): array;

    /**
     * Build a mutator bound to this mutation.
     *
     * Not resolved from the container: a mutator writes into one buffer, and every
     * mutation needs its own. Generated code knows which handlers to pull alongside it.
     */
    public function mutatorFor(string $entity, MutationBuffer $buffer): object;

    /**
     * The finder for an entity, from the container. Its dependencies are all
     * application handlers, so it has nothing per-request in it.
     */
    public function finder(string $entity): object;

    /**
     * A declared query's parameters, in order, so named arguments can be positioned.
     *
     * @return list<string>
     */
    public function queryArguments(string $entity, string $query): array;

    /**
     * Apply raw input to a mutation.
     *
     * The one thing a name-addressed layer cannot do for itself: a GraphQL argument
     * arrives as a string and a setter wants a DateTimeImmutable. Generated code knows
     * both ends, so it does the conversion and the gateway stays untyped only at its
     * edge.
     *
     * @param array<string, mixed> $input
     */
    public function apply(string $entity, MutationBuffer $buffer, array $input): void;

    /**
     * Every interface the application must implement before it can start.
     *
     * @return list<string> Fully-qualified names.
     */
    public function contracts(): array;
}
