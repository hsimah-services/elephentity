<?php

declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Eleph\\GraphQL\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
}, prepend: true);

use Eleph\GraphQL\Manifest\{Manifest, ObjectTypeEntry, FieldEntry, GraphQLType, ConnectionEntry, MutationEntry, RootFieldEntry, QueryFieldEntry};
use Eleph\GraphQL\Registration\{MutationRegistrar, TypeRegistrar};
use Eleph\GraphQL\Relay\GlobalId;
use Eleph\GraphQL\Relay\WrongIdType;
use Eleph\GraphQL\Resolver\Connections;
use Eleph\GraphQL\SchemaBuilder;
use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\MutationResult;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Storage\{Cursor, Offset, Page};
use GraphQL\GraphQL;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $call): void {
    try { $call(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Invalid input was accepted.');
}

final class TestQuery implements EntityQuery {
    public int $pageCalls = 0;
    public array $items;
    public function __construct() { $this->items = [new stdClass(), new stdClass(), new stdClass()]; }
    public function count(): int { return count($this->items); }
    public function all(): array { return $this->items; }
    public function first(): ?object { return $this->items[0] ?? null; }
    public function exists(): bool { return $this->items !== []; }
    public function page(int $limit, ?Cursor $after = null): Page {
        ++$this->pageCalls;
        $start = Offset::fromCursor($after)->value;
        return new Page(array_slice($this->items, $start, $limit), $start + $limit < count($this->items) ? (new Offset($start + $limit))->toCursor() : null);
    }
}
class TestGateway implements EntityGateway {
    public array $calls = [];
    public array $input = [];
    public ?object $queryResult = null;
    public function find(string $entity, EntityId $id): ?object { $this->calls[] = [$entity, (string) $id]; return null; }
    public function all(string $entity): EntityQuery { return new TestQuery(); }
    public function runQuery(string $entity, string $query, array $args): ?object { return $this->queryResult; }
    public function create(string $entity, array $input): MutationResult { $this->calls[] = [$entity]; $this->input = $input; return new MutationResult(EntityId::of(1), null); }
    public function update(string $entity, EntityId $id, array $input): MutationResult { $this->calls[] = [$entity, (string) $id]; $this->input = $input; return new MutationResult($id, null); }
    public function runAction(string $entity, string $action, EntityId $id, array $args): MutationResult { return $this->update($entity, $id, $args); }
    public function runActions(string $entity, EntityId $id, array $actions): MutationResult { throw new LogicException('Unused'); }
    public function delete(string $entity, EntityId $id): void { $this->calls[] = [$entity, (string) $id]; }
}

// Public type names differ from domain entity names; matching must use the public type.
$manifest = new Manifest(
    objects: [
        'ApiBook' => new ObjectTypeEntry('ApiBook', 'Book', ['author' => new FieldEntry('author', new GraphQLType('ApiAuthor'), 'getAuthor')], ['authors' => new ConnectionEntry('authors', 'ApiBook', 'ApiAuthor', 'authors', 'authors')]),
        'ApiAuthor' => new ObjectTypeEntry('ApiAuthor', 'Author', []),
    ],
    mutations: [
        'createApiBook' => new MutationEntry('createApiBook', 'create', 'Book', ['author' => new GraphQLType('ID'), 'authors' => new GraphQLType('ID', list: true)]),
        'updateApiBook' => new MutationEntry('updateApiBook', 'update', 'Book', ['id' => new GraphQLType('ID')]),
        'publishApiBook' => new MutationEntry('publishApiBook', 'action', 'Book', ['id' => new GraphQLType('ID')], 'publish'),
    ],
    roots: ['ApiBook' => new RootFieldEntry('ApiBook', 'ApiBooks', 'Book')],
);
$gateway = new TestGateway();
$configs = (new MutationRegistrar($manifest, $gateway))->configs();
$wrong = GlobalId::encode('ApiAuthor', 1);
foreach (['deleteApiBook', 'updateApiBook', 'publishApiBook'] as $name) {
    rejects(fn () => $configs[$name]['mutateAndGetPayload'](['id' => $wrong]));
}
$root = (new TypeRegistrar($manifest, $gateway))->rootFieldConfigs()[0]['field']['resolve'];
rejects(fn () => $root(null, ['id' => $wrong]));
rejects(fn () => $configs['createApiBook']['mutateAndGetPayload'](['author' => GlobalId::encode('ApiBook', 1)]));
rejects(fn () => $configs['createApiBook']['mutateAndGetPayload'](['authors' => [$wrong, GlobalId::encode('ApiBook', 1)]]));
check($gateway->calls === [], 'A rejected ID reached the gateway.');
try { GlobalId::raw($wrong, 'ApiBook'); } catch (WrongIdType $e) { check($e->isClientSafe() && $e->getMessage() === 'Expected a ApiBook ID, received ApiAuthor.', 'A wrong-type ID error is not client-safe.'); }
$configs['createApiBook']['mutateAndGetPayload'](['author' => $wrong, 'authors' => [$wrong, '2']]);
check($gateway->input === ['author' => '1', 'authors' => ['1', '2']], 'Matching relationship IDs were not decoded.');
$configs['updateApiBook']['mutateAndGetPayload'](['id' => GlobalId::encode('ApiBook', 1)]);
$configs['deleteApiBook']['mutateAndGetPayload'](['id' => '1']);
check($gateway->calls === [['Book'], ['Book', '1'], ['Book', '1']], 'Matching or raw IDs dispatched incorrectly.');

$query = new TestQuery();
$connections = new Connections();
foreach ([['last' => 1], ['before' => 'cursor'], ['first' => -1]] as $args) rejects(fn () => $connections->resolve($query, $args));
$zero = $connections->resolve($query, ['first' => 0]);
check($zero['nodes'] === [] && $zero['edges'] === [] && $zero['pageInfo']['endCursor'] === null, 'first:0 returned data.');
check($zero['pageInfo']['hasNextPage'] && $query->pageCalls === 0, 'first:0 queried rows or lost page information.');
$first = $connections->resolve($query, ['first' => 2]);
$next = $connections->resolve($query, ['first' => 2, 'after' => $first['pageInfo']['endCursor']]);
check($first['nodes'] === array_slice($query->items, 0, 2) && $next['nodes'] === [$query->items[2]], 'Forward pages overlap or lose rows.');
check(!$next['pageInfo']['hasNextPage'], 'Last page says more rows remain.');

$builder = new SchemaBuilder();
$builder->field('RootQuery', 'hello', ['type' => 'String', 'resolve' => static fn () => 'hello']);
$schema = $builder->build();
$schema->assertValid();
check($schema->getMutationType() === null, 'Read-only schema has a mutation root.');
check(GraphQL::executeQuery($schema, '{ hello }')->toArray() === ['data' => ['hello' => 'hello']], 'Read-only query failed.');
echo "PASS: typed IDs, relationship inputs, pagination boundaries and read-only schema\n";

$singularManifest = new Manifest(objects: ['ApiBook' => new ObjectTypeEntry('ApiBook', 'Book', ['title' => new FieldEntry('title', new GraphQLType('String'), 'getTitle')])], queries: [
    'bookBySlug' => new QueryFieldEntry('bookBySlug', 'ApiBook', false, 'Book', 'bySlug'),
    'searchBooks' => new QueryFieldEntry('searchBooks', 'ApiBook', true, 'Book', 'search'),
]);
$gateway = new TestGateway();
$configs = (new TypeRegistrar($singularManifest, $gateway))->rootFieldConfigs();
$resolve = $configs[0]['field']['resolve'];
foreach ([(object) ['title' => 'Visible'], null, null] as $result) {
    $gateway->queryResult = $result;
    check($resolve(null, []) === $result, 'Singular query must preserve the entity or null.');
}
$connection = (new TypeRegistrar($singularManifest, $gateway))->connectionConfigs()[0]['resolve'];
$gateway->queryResult = new TestQuery([(object) ['title' => 'Visible']]);
check(count($connection(null, ['first' => 1])['nodes']) === 1, 'Collection query must still resolve a page.');
$gateway->queryResult = (object) [];
try { $connection(null, []); throw new LogicException('Scalar collection result accepted.'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'must return EntityQuery'), 'Collection contract failure missing.'); }
echo "PASS: singular entity/null query results and collection result contract\n";

// A schema from a manifest and a gateway alone: Node, PageInfo and node(id:) come with the package.
final class NodeGateway extends TestGateway {
    public function find(string $entity, EntityId $id): ?object { return $entity === 'Book' && (string) $id === '1' ? new class { public function getId(): string { return '1'; } } : null; }
}
$nodeManifest = new Manifest(
    objects: [
        'ApiBook' => new ObjectTypeEntry('ApiBook', 'Book', ['id' => new FieldEntry('id', new GraphQLType('ID', nonNull: true), 'getId')]),
        'ApiAuthor' => new ObjectTypeEntry('ApiAuthor', 'Author', ['id' => new FieldEntry('id', new GraphQLType('ID', nonNull: true), 'getId')]),
    ],
    roots: ['ApiBook' => new RootFieldEntry('ApiBook', 'ApiBooks', 'Book'), 'ApiAuthor' => new RootFieldEntry('ApiAuthor', 'ApiAuthors', 'Author')],
);
$schema = SchemaBuilder::fromManifest($nodeManifest, new NodeGateway())->build();
$schema->assertValid();
$node = static fn (string $id): array => GraphQL::executeQuery($schema, '{ node(id: "' . $id . '") { __typename id } }')->toArray();
check(($node(GlobalId::encode('ApiBook', 1))['data']['node']['__typename'] ?? null) === 'ApiBook', 'node(id:) did not resolve an entity type.');
foreach ([GlobalId::encode('ApiBook', 2), GlobalId::encode('Missing', 1), GlobalId::encode('ApiAuthor', 1), 'not-an-id'] as $id) {
    check($node($id) === ['data' => ['node' => null]], 'node(id:) returned something for an unresolvable ID.');
}
echo "PASS: Node, PageInfo and node(id:) from a manifest alone\n";
