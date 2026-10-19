<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Naming;

use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\FieldDefinition;
use Eleph\Gen\Php\PhpConfig;

/**
 * Every name the generator produces, in one place.
 *
 * Naming is the framework's most load-bearing convention — WPGraphQL maps a spec field
 * to getField(), lint rules assume class shapes, and a developer navigating an
 * unfamiliar entity relies on the names being the same everywhere. Deriving them in
 * one class rather than at each call site is what makes that hold.
 */
final readonly class Names
{
    private const CONTRACT = 'Contract';

    public function __construct(private PhpConfig $config)
    {
    }

    /**
     * Everything belonging to one entity lives under a folder named after it.
     *
     * Navigating an unfamiliar codebase is the point: `Item/` holds the entity, its
     * mutator, its finder, its bridges, and — in `Item/Contract/` — precisely the
     * interfaces someone has to implement before the application will boot. Answering
     * "what do I owe this entity?" becomes listing one directory.
     */
    public function entity(EntityDefinition $entity): string
    {
        return $this->config->namespaceFor($entity->name, $entity->name);
    }

    public function mutator(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Mutator');
    }

    public function finder(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Finder');
    }

    public function preCommitContext(EntityDefinition $entity): string
    {
        return $this->member($entity, 'PreCommitContext');
    }

    public function mutationContext(EntityDefinition $entity): string
    {
        return $this->member($entity, 'MutationContext');
    }

    public function readPolicies(EntityDefinition $entity): string
    {
        return $this->member($entity, 'ReadPolicies');
    }

    public function writePolicies(EntityDefinition $entity): string
    {
        return $this->member($entity, 'WritePolicies');
    }

    public function writeContext(EntityDefinition $entity): string
    {
        return $this->member($entity, 'WriteContext');
    }

    public function actionArguments(EntityDefinition $entity, string $action): string
    {
        return $this->member($entity, ucfirst($action) . 'Arguments');
    }

    public function verifiers(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Verifiers');
    }

    public function sideEffects(EntityDefinition $entity): string
    {
        return $this->member($entity, 'SideEffects');
    }

    public function hydrator(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Hydrator');
    }

    public function deleter(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Deleter');
    }

    public function input(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Input');
    }

    /**
     * The one class that is about the project rather than an entity, so it sits at the
     * root of the generated tree rather than in a folder.
     */
    public function catalogue(): string
    {
        return $this->config->namespaceFor('Catalogue');
    }

    /**
     * The one class listing every generated class's container factory, beside
     * `Catalogue` for the same reason: it is about the project, not one entity.
     */
    public function wiring(): string
    {
        return $this->config->namespaceFor('Wiring');
    }

    public function actionContext(EntityDefinition $entity, string $action): string
    {
        return $this->member($entity, ucfirst($action) . 'Context');
    }

    public function queryHandler(EntityDefinition $entity, string $query): string
    {
        return $this->contract($entity, ucfirst($query) . 'Query');
    }

    public function actionHandler(EntityDefinition $entity, string $action): string
    {
        return $this->contract($entity, ucfirst($action) . 'Action');
    }

    public function sideEffectHandler(EntityDefinition $entity, string $sideEffect): string
    {
        return $this->contract($entity, ucfirst($sideEffect) . 'SideEffect');
    }

    public function readPolicyHandler(EntityDefinition $entity, string $policy): string
    {
        return $this->contract($entity, ucfirst($policy) . 'ReadPolicy');
    }

    public function writePolicyHandler(EntityDefinition $entity, string $policy): string
    {
        return $this->contract($entity, ucfirst($policy) . 'WritePolicy');
    }

    public function patternReadPolicyHandler(string $pattern, string $policy): string
    {
        return $this->config->namespaceFor('Pattern', $pattern, self::CONTRACT, $pattern . ucfirst($policy) . 'ReadPolicy');
    }

    public function patternWritePolicyHandler(string $pattern, string $policy): string
    {
        return $this->config->namespaceFor('Pattern', $pattern, self::CONTRACT, $pattern . ucfirst($policy) . 'WritePolicy');
    }

    public function fieldVerifier(EntityDefinition $entity, FieldDefinition $field): string
    {
        return $this->contract($entity, ucfirst($field->name) . 'Verifier');
    }

    /**
     * Type processors belong to a type rather than an entity, so they sit outside the
     * entity folders — Money is shared, and filing it under whichever entity happened
     * to use it first would be arbitrary.
     */
    public function readProcessor(string $type): string
    {
        return $this->config->namespaceFor('Type', $type . 'ReadProcessor');
    }

    public function writeProcessor(string $type): string
    {
        return $this->config->namespaceFor('Type', $type . 'WriteProcessor');
    }

    /**
     * A user-written value class, which the generator names but never emits.
     */
    public function valueClass(string $type): string
    {
        return trim($this->config->typeNamespace, '\\') . '\\' . $type;
    }

    /**
     * An enum's class, whether declared in types/ or written inline on a field.
     *
     * Both live in Enum/, outside the entity folders, and that placement is load
     * bearing: it is what makes promoting an inline enum into types/ a no-op in the
     * generated code. Filing inline enums under their entity would break that.
     */
    public function enum(string $name): string
    {
        return $this->config->namespaceFor('Enum', $name);
    }

    public function inlineEnum(EntityDefinition $entity, FieldDefinition $field): string
    {
        return $this->enum($entity->name . ucfirst($field->name));
    }

    /**
     * A pattern's shared interface, named after the pattern itself — Auditable, not
     * PostAuditable — because every entity using it implements the same one.
     *
     * Outside the entity folders, like Type/ and Enum/: a pattern belongs to no single
     * entity, and filing it under whichever one happened to use it first would be
     * arbitrary.
     */
    public function patternContract(string $pattern): string
    {
        return $this->config->namespaceFor('Pattern', $pattern, $pattern);
    }

    /**
     * The trait an entity's mutator `use`s to pick up the pattern's setters.
     *
     * A trait rather than a base class: PHP allows one parent but many traits, and two
     * patterns on the same entity both wanting to contribute setters is not a
     * hypothetical — Auditable and SoftDeletable can both apply to Post.
     */
    public function patternMutatorTrait(string $pattern): string
    {
        return $this->config->namespaceFor('Pattern', $pattern, $pattern . 'MutatorTrait');
    }

    private function member(EntityDefinition $entity, string $suffix): string
    {
        return $this->config->namespaceFor($entity->name, $entity->name . $suffix);
    }

    private function contract(EntityDefinition $entity, string $suffix): string
    {
        return $this->config->namespaceFor(
            $entity->name,
            self::CONTRACT,
            $entity->name . $suffix,
        );
    }

    public function getter(string $field): string
    {
        return 'get' . ucfirst($field);
    }

    public function setter(string $field): string
    {
        return 'set' . ucfirst($field);
    }

    /**
     * Where a class is written, relative to the output directory.
     *
     * Relative deliberately: this path is hashed into the file's digest, so an
     * absolute one would make every signature depend on where the project happens to
     * be checked out.
     */
    public function pathFor(string $fullyQualified): string
    {
        $relative = substr($fullyQualified, strlen(trim($this->config->rootNamespace, '\\')) + 1);

        return str_replace('\\', '/', $relative) . '.php';
    }
}
