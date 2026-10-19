<?php

declare(strict_types=1);

// Assembles the schema the PHP stack (elephentity-graphql-php on webonyx) builds from a generated manifest, and prints its
// introspection as JSON. tools/refresh-parity runs it; the C# schema must print the same SDL after the same normalisation.
//
//   php assemble.php <vendor/autoload.php> <elephentity-graphql-php/src> <graphql-manifest.php>
//
// The assembly is the one the PHP application does (clog's server/standalone/src/GraphQL.php, minus clog): Node, PageInfo and
// node(id:) are the application's to register in PHP, and the .NET package registers them itself.

require $argv[1];
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'Eleph\\GraphQL\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[2] . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
}, prepend: true);

use Eleph\GraphQL\Registration\{MutationRegistrar, TypeRegistrar};
use Eleph\GraphQL\SchemaBuilder;
use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\MutationResult;
use Eleph\Runtime\Query\EntityQuery;
use GraphQL\Type\Introspection;

$gateway = new class implements EntityGateway {
    public function find(string $entity, EntityId $id): ?object { return null; }
    public function all(string $entity): EntityQuery { throw new LogicException('Not executed.'); }
    public function runQuery(string $entity, string $query, array $args): ?object { return null; }
    public function create(string $entity, array $input): MutationResult { throw new LogicException('Not executed.'); }
    public function update(string $entity, EntityId $id, array $input): MutationResult { throw new LogicException('Not executed.'); }
    public function runAction(string $entity, string $action, EntityId $id, array $args): MutationResult { throw new LogicException('Not executed.'); }
    public function runActions(string $entity, EntityId $id, array $actions): MutationResult { throw new LogicException('Not executed.'); }
    public function delete(string $entity, EntityId $id): void {}
};

$manifest = require $argv[3];

$builder = new SchemaBuilder();
$builder->interface('Node', ['id' => ['type' => ['non_null' => 'ID']]], static fn () => null);
$builder->object('PageInfo', ['fields' => [
    'hasNextPage' => ['type' => ['non_null' => 'Boolean']],
    'hasPreviousPage' => ['type' => ['non_null' => 'Boolean']],
    'startCursor' => ['type' => 'String'],
    'endCursor' => ['type' => 'String'],
]]);
$types = new TypeRegistrar($manifest, $gateway);
foreach ($types->enumConfigs() as $name => $config) {
    $builder->enum($name, $config);
}
foreach ($types->objectConfigs() as $name => $config) {
    $builder->object($name, $config);
}
foreach ($types->rootFieldConfigs() as $config) {
    $builder->field('RootQuery', $config['name'], $config['field']);
}
foreach ($types->connectionConfigs() as $config) {
    $builder->connection($config);
}
foreach ((new MutationRegistrar($manifest, $gateway))->configs() as $name => $config) {
    $builder->mutation($name, $config);
}
$builder->field('RootQuery', 'node', ['type' => 'Node', 'args' => ['id' => ['type' => ['non_null' => 'ID']]], 'resolve' => static fn () => null]);

$schema = $builder->build();
$schema->assertValid();

echo json_encode(Introspection::fromSchema($schema), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
