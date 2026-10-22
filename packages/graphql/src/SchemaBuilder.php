<?php

declare(strict_types=1);
namespace Eleph\GraphQL;

use GraphQL\Type\Definition\{Type, ObjectType, InputObjectType, InterfaceType, EnumType};
use GraphQL\Type\Schema;
use Eleph\GraphQL\Manifest\Manifest;
use Eleph\GraphQL\Registration\{MutationRegistrar, TypeRegistrar};
use Eleph\GraphQL\Relay\GlobalId;
use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Type\ProcessorRegistry;
use RuntimeException;
use WeakMap;

/** Turns Elephentity registration configs into a webonyx schema, without global hooks. */
final class SchemaBuilder
{
    private array $configs = ['RootQuery' => ['fields' => []], 'RootMutation' => ['fields' => []]];
    private array $types = [];
    /**
     * The whole generated surface, plus the Relay scaffolding it refers to: `Node`,
     * `PageInfo` and `node(id:)`. A consumer adds only its own fields on top.
     */
    public static function fromManifest(Manifest $manifest, EntityGateway $gateway, ?ProcessorRegistry $processors = null): self
    {
        $builder = new self();
        $builder->relay($manifest, $gateway);
        $types = new TypeRegistrar($manifest, $gateway, processors: $processors);
        foreach ($types->enumConfigs() as $name => $config) $builder->enum($name, $config);
        foreach ($types->objectConfigs() as $name => $config) $builder->object($name, $config);
        foreach ($types->rootFieldConfigs() as $config) $builder->field('RootQuery', $config['name'], $config['field']);
        foreach ($types->connectionConfigs() as $config) $builder->connection($config);
        foreach ((new MutationRegistrar($manifest, $gateway))->configs() as $name => $config) $builder->mutation($name, $config);
        return $builder;
    }
    /** `Node`, `PageInfo` and `node(id:)`: null for an id of an unknown type, a missing row or a malformed id. */
    private function relay(Manifest $manifest, EntityGateway $gateway): void
    {
        $typeOf = new WeakMap();
        $entities = [];
        foreach ($manifest->roots as $root) $entities[$root->type] = $root->entity;
        $this->interface('Node', ['id' => ['type' => ['non_null' => 'ID']]], static fn (object $value): ?string => $typeOf[$value] ?? null);
        $this->object('PageInfo', ['fields' => [
            'hasNextPage' => ['type' => ['non_null' => 'Boolean']],
            'hasPreviousPage' => ['type' => ['non_null' => 'Boolean']],
            'startCursor' => ['type' => 'String'],
            'endCursor' => ['type' => 'String'],
        ]]);
        $this->field('RootQuery', 'node', [
            'type' => 'Node',
            'description' => 'Fetch any object by its global ID.',
            'args' => ['id' => ['type' => ['non_null' => 'ID']]],
            'resolve' => static function (mixed $source, array $args) use ($gateway, $entities, $typeOf): ?object {
                $global = is_string($args['id'] ?? null) ? GlobalId::decode($args['id']) : null;
                if ($global === null || !isset($entities[$global->type])) return null;
                $id = GlobalId::entityId($global->id);
                $entity = $id === null ? null : $gateway->find($entities[$global->type], $id);
                if ($entity !== null) $typeOf[$entity] = $global->type;
                return $entity;
            },
        ]);
    }
    public function object(string $name, array $config): void { $this->configs[$name] = $config; }
    public function input(string $name, array $fields): void { $this->configs[$name] = ['input' => true, 'fields' => $fields]; }
    public function field(string $type, string $name, array $config): void { $this->configs[$type]['fields'][$name] = $config; }
    public function interface(string $name, array $fields, callable $resolve): void
    {
        $this->types[$name] = new InterfaceType(['name' => $name, 'fields' => fn () => $this->fields($fields), 'resolveType' => $resolve]);
    }
    public function enum(string $name, array $config): void { $this->types[$name] = new EnumType(['name' => $name, ...$config]); }
    public function type(string|array $config): Type
    {
        if (is_array($config)) {
            if (isset($config['non_null'])) return Type::nonNull($this->type($config['non_null']));
            if (isset($config['list_of'])) return Type::listOf($this->type($config['list_of']));
            throw new RuntimeException('Unknown type wrapper.');
        }
        if (isset($this->types[$config])) return $this->types[$config];
        $scalar = match ($config) { 'String' => Type::string(), 'ID' => Type::id(), 'Int' => Type::int(), 'Float' => Type::float(), 'Boolean' => Type::boolean(), default => null };
        if ($scalar) return $scalar;
        $definition = $this->configs[$config] ?? throw new RuntimeException('Unknown GraphQL type ' . $config);
        $options = ['name' => $config, 'description' => $definition['description'] ?? null, 'fields' => fn () => $this->fields($this->configs[$config]['fields'])];
        return $this->types[$config] = isset($definition['input']) ? new InputObjectType($options) : new ObjectType([
            ...$options, 'interfaces' => fn () => array_map($this->type(...), $definition['interfaces'] ?? []),
        ]);
    }
    private function fields(array $fields): array
    {
        foreach ($fields as &$field) {
            $field['type'] = $this->type($field['type']);
            if (isset($field['args'])) $field['args'] = $this->fields($field['args']);
        }
        return $fields;
    }
    public function connection(array $config): void
    {
        $from = $config['fromType']; $to = $config['toType']; $field = $config['fromFieldName'];
        $name = $from . 'To' . (isset($config['connectionArgs']) ? ucfirst($field) : $to) . 'Connection';
        $edge = $name . 'Edge';
        $this->object($edge, ['fields' => ['cursor' => ['type' => 'String'], 'node' => ['type' => $to]]]);
        $this->object($name, ['fields' => [
            'nodes' => ['type' => ['list_of' => $to]],
            'edges' => ['type' => ['list_of' => $edge]],
            'pageInfo' => ['type' => ['non_null' => 'PageInfo']],
            ...$config['connectionFields'],
        ]]);
        $args = ['first' => ['type' => 'Int'], 'after' => ['type' => 'String'], 'last' => ['type' => 'Int'], 'before' => ['type' => 'String']];
        if (isset($config['connectionArgs'])) {
            $this->input($name . 'WhereArgs', $config['connectionArgs']);
            $args['where'] = ['type' => $name . 'WhereArgs'];
        }
        $this->field($from, $field, ['type' => $name, 'args' => $args, 'resolve' => $config['resolve']]);
    }
    public function mutation(string $name, array $config): void
    {
        $input = ucfirst($name) . 'Input'; $output = ucfirst($name) . 'Payload';
        $clientId = ['clientMutationId' => ['type' => 'String']];
        $this->input($input, [...$config['inputFields'], ...$clientId]);
        $this->object($output, ['fields' => [...$config['outputFields'], ...$clientId]]);
        $this->field('RootMutation', $name, [
            'type' => $output, 'args' => ['input' => ['type' => ['non_null' => $input]]],
            'resolve' => static function ($source, array $args) use ($config): array {
                $values = $args['input']; $clientId = $values['clientMutationId'] ?? null; unset($values['clientMutationId']);
                return [...($config['mutateAndGetPayload'])($values), 'clientMutationId' => $clientId];
            },
        ]);
    }
    public function build(): Schema
    {
        $names = array_keys($this->configs);
        $mutation = $this->configs['RootMutation']['fields'] === [] ? null : $this->type('RootMutation');
        if ($mutation === null) $names = array_values(array_diff($names, ['RootMutation']));
        return new Schema(['query' => $this->type('RootQuery'), 'mutation' => $mutation, 'types' => fn () => array_map($this->type(...), $names)]);
    }
}
