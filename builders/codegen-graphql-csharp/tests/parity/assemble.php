<?php

declare(strict_types=1);

// Assembles the schema the PHP stack (elephentity-graphql-php on webonyx) builds from a generated manifest, and prints its
// introspection as JSON. tools/refresh-parity runs it; the C# schema must print the same SDL after the same normalisation.
//
//   php assemble.php <vendor/autoload.php> <elephentity-graphql-php/src> <graphql-manifest.php>
//
// SchemaBuilder::fromManifest registers Node, PageInfo and node(id:) itself, as the .NET package does.

require $argv[1];
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'Eleph\\GraphQL\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[2] . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
}, prepend: true);

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

$builder = SchemaBuilder::fromManifest($manifest, $gateway);

$schema = $builder->build();
$schema->assertValid();

echo json_encode(Introspection::fromSchema($schema), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
