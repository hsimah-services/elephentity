<?php

declare(strict_types=1);

namespace PheFr\Codegen\Naming;

use PheFr\Codegen\GeneratorConfig;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\FieldDefinition;

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

    public function __construct(private GeneratorConfig $config)
    {
    }

    public function entity(EntityDefinition $entity): string
    {
        return $this->config->namespaceFor($entity->name);
    }

    public function mutator(EntityDefinition $entity): string
    {
        return $this->config->namespaceFor($entity->name . 'Mutator');
    }

    public function finder(EntityDefinition $entity): string
    {
        return $this->config->namespaceFor($entity->name . 'Finder');
    }

    public function mutationContext(EntityDefinition $entity): string
    {
        return $this->config->namespaceFor($entity->name . 'MutationContext');
    }

    public function actionContext(EntityDefinition $entity, string $action): string
    {
        return $this->config->namespaceFor(
            'Context',
            $entity->name . ucfirst($action) . 'Context',
        );
    }

    public function queryHandler(EntityDefinition $entity, string $query): string
    {
        return $this->config->namespaceFor(
            self::CONTRACT,
            'Query',
            $entity->name . ucfirst($query) . 'Query',
        );
    }

    public function actionHandler(EntityDefinition $entity, string $action): string
    {
        return $this->config->namespaceFor(
            self::CONTRACT,
            'Action',
            $entity->name . ucfirst($action) . 'Action',
        );
    }

    public function triggerHandler(EntityDefinition $entity, string $trigger): string
    {
        return $this->config->namespaceFor(
            self::CONTRACT,
            'Trigger',
            $entity->name . ucfirst($trigger) . 'Trigger',
        );
    }

    public function fieldVerifier(EntityDefinition $entity, FieldDefinition $field): string
    {
        return $this->config->namespaceFor(
            self::CONTRACT,
            'Verifier',
            $entity->name . ucfirst($field->name) . 'Verifier',
        );
    }

    public function readProcessor(string $type): string
    {
        return $this->config->namespaceFor(self::CONTRACT, 'Type', $type . 'ReadProcessor');
    }

    public function writeProcessor(string $type): string
    {
        return $this->config->namespaceFor(self::CONTRACT, 'Type', $type . 'WriteProcessor');
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
     * Both forms resolve here, which is what makes promoting an inline enum to a
     * declared type a no-op in the generated code.
     */
    public function enum(string $name): string
    {
        return $this->config->namespaceFor('Enum', $name);
    }

    public function inlineEnum(EntityDefinition $entity, FieldDefinition $field): string
    {
        return $this->enum($entity->name . ucfirst($field->name));
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
