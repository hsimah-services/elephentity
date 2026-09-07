<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Naming;

use Eleph\Codegen\Php\PhpConfig;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;

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

    public function mutationContext(EntityDefinition $entity): string
    {
        return $this->member($entity, 'MutationContext');
    }

    public function verifiers(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Verifiers');
    }

    public function triggers(EntityDefinition $entity): string
    {
        return $this->member($entity, 'Triggers');
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

    public function triggerHandler(EntityDefinition $entity, string $trigger): string
    {
        return $this->contract($entity, ucfirst($trigger) . 'Trigger');
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
