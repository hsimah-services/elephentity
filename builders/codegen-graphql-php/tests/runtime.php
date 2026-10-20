<?php

declare(strict_types=1);
require __DIR__ . '/support.php';

use Eleph\GraphQL\SchemaBuilder;
use Eleph\GraphQL\Registration\{TypeRegistrar, MutationRegistrar};
use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\MutationResult;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Storage\{Cursor, Page, Offset};
use GraphQL\GraphQL;

final class Book
{
    public function __construct(private EntityId $id, public string $title, public string $status) {}
    public function getId(): EntityId { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getStatus(): string { return $this->status; }
}
final class Books implements EntityQuery
{
    public function __construct(private array $books) {}
    public function count(): int { return count($this->books); }
    public function all(): array { return $this->books; }
    public function first(): ?object { return $this->books[0] ?? null; }
    public function exists(): bool { return $this->books !== []; }
    public function page(int $limit, ?Cursor $after = null): Page {
        $offset = Offset::fromCursor($after)->value;
        return new Page(array_slice($this->books, $offset, $limit), $offset + $limit < count($this->books) ? (new Offset($offset + $limit))->toCursor() : null);
    }
}
final class Gateway implements EntityGateway
{
    public array $books = [];
    public function find(string $entity, EntityId $id): ?object { return $this->books[(string) $id] ?? null; }
    public function all(string $entity): EntityQuery { return new Books(array_values($this->books)); }
    public function runQuery(string $entity, string $query, array $args): EntityQuery {
        check($query === 'search', 'Wrong generated query method');
        return new Books(array_values(array_filter($this->books, fn ($book) => str_contains($book->title, $args['text'] ?? ''))));
    }
    public function create(string $entity, array $input): MutationResult {
        check($entity === 'Book', 'Wrong generated entity');
        $id = EntityId::of(count($this->books) + 1);
        $book = new Book($id, $input['title'], $input['status']); $this->books[(string) $id] = $book;
        return new MutationResult($id, $book);
    }
    public function update(string $entity, EntityId $id, array $input): MutationResult {
        $book = $this->find($entity, $id); foreach ($input as $key => $value) $book->$key = $value;
        return new MutationResult($id, $book);
    }
    public function runAction(string $entity, string $action, EntityId $id, array $args): MutationResult {
        check($action === 'rename', 'Wrong generated action'); return $this->update($entity, $id, $args);
    }
    public function runActions(string $entity, EntityId $id, array $actions): MutationResult { throw new LogicException('Unused'); }
    public function delete(string $entity, EntityId $id): void { unset($this->books[(string) $id]); }
}
function schema(string $directory, Gateway $gateway): \GraphQL\Type\Schema {
    $manifest = require $directory . '/graphql-manifest.php';
    $builder = new SchemaBuilder();
    $builder->interface('Node', ['id' => ['type' => ['non_null' => 'ID']]], fn () => $builder->type('Book'));
    $builder->object('PageInfo', ['fields' => [
        'hasNextPage' => ['type' => ['non_null' => 'Boolean']], 'hasPreviousPage' => ['type' => ['non_null' => 'Boolean']],
        'startCursor' => ['type' => 'String'], 'endCursor' => ['type' => 'String'],
    ]]);
    $types = new TypeRegistrar($manifest, $gateway);
    foreach ($types->enumConfigs() as $name => $config) $builder->enum($name, $config);
    foreach ($types->objectConfigs() as $name => $config) $builder->object($name, $config);
    foreach ($types->rootFieldConfigs() as $config) $builder->field('RootQuery', $config['name'], $config['field']);
    foreach ($types->connectionConfigs() as $config) {
        $resolver = $config['resolve'];
        $config['resolve'] = static fn ($source, array $args) => $resolver($source, array_replace($args, $args['where'] ?? []));
        $builder->connection($config);
    }
    foreach ((new MutationRegistrar($manifest, $gateway))->configs() as $name => $config) $builder->mutation($name, $config);
    $schema = $builder->build(); $schema->assertValid(); return $schema;
}
foreach (['clog', 'relations', 'book'] as $case) {
    generated(fixture($case), function (string $directory) use ($case): void {
        $manifest = require $directory . '/graphql-manifest.php';
        check($manifest instanceof \Eleph\GraphQL\Manifest\Manifest, 'Wrong generated manifest type');
        $verifier = require $directory . '/verify.php';
        check($verifier instanceof \Eleph\Runtime\Conformance\Verifier, 'Wrong generated verifier type');
        check($verifier->verify(fn () => 'MissingEntity') !== [], 'Verifier did not catch missing entities');
        check($verifier->verify(fn () => stdClass::class) !== [], 'Verifier did not catch missing accessors');
        if ($case !== 'book') {
            schema($directory, new Gateway());
            echo "PASS: $case manifest, webonyx schema and stale-entity verification\n"; return;
        }
        check($verifier->verify(fn () => Book::class) === [], 'Verifier rejected matching entity accessors');
        $gateway = new Gateway(); $schema = schema($directory, $gateway);
        $execute = static function (string $query, array $variables = []) use ($schema): array {
            $result = GraphQL::executeQuery($schema, $query, variableValues: $variables)->toArray();
            check(!isset($result['errors']), json_encode($result)); return $result['data'];
        };
        $created = $execute('mutation { createBook(input:{title:"First",status:DRAFT,clientMutationId:"m1"}) { clientMutationId book { id title status } } }');
        check($created['createBook']['clientMutationId'] === 'm1', 'Missing Relay clientMutationId');
        $id = $created['createBook']['book']['id'];
        $execute('mutation { createBook(input:{title:"Second",status:LIVE}) { book { id } } }');
        $page = $execute('{ books(first:1) { totalCount nodes { title } pageInfo {hasNextPage endCursor} } }')['books'];
        check($page['totalCount'] === 2 && $page['pageInfo']['hasNextPage'], 'Invalid generated connection');
        $next = $execute('query($after:String) {books(first:1,after:$after){nodes{title} pageInfo{hasNextPage}}}', ['after' => $page['pageInfo']['endCursor']]);
        check($next['books']['nodes'][0]['title'] === 'Second' && !$next['books']['pageInfo']['hasNextPage'], 'Invalid next page');
        $execute('mutation($id:ID!){renameBook(input:{id:$id,title:"Renamed"}){book{title}}}', ['id' => $id]);
        $book = $execute('query($id:ID!){book(id:$id){title status}}', ['id' => $id]);
        check($book['book']['title'] === 'Renamed' && $book['book']['status'] === 'DRAFT', 'Action, ID or enum mapping failed');
        $search = $execute('{searchBooks(where:{text:"Renamed"}){totalCount nodes{title}}}');
        check($search['searchBooks']['totalCount'] === 1, 'Custom query arguments failed');
        $execute('mutation($id:ID!){deleteBook(input:{id:$id}){deletedId}}', ['id' => $id]);
        check($gateway->all('Book')->count() === 1, 'Delete mutation failed');
        echo "PASS: generated schema queries, Relay pagination, IDs, enums, mutations, actions and verifier\n";
    });
}

generated(fixture('book'), function (string $directory): void {
    $runtimeSchema = schema($directory, new Gateway());
    foreach (array_keys($runtimeSchema->getTypeMap()) as $name) {
        if (!str_ends_with($name, 'Connection') && !str_ends_with($name, 'ConnectionEdge') && !str_ends_with($name, 'Input') && !str_ends_with($name, 'Payload') && !str_ends_with($name, 'WhereArgs')) continue;
        $request = fixture('book');
        $shadow = $request['schema']['entities']['Book'];
        $shadow['name'] = 'Shadow';
        $shadow['fields'] = []; $shadow['queries'] = []; $shadow['actions'] = [];
        $shadow['integrations']['graphql'] = ['singular' => $name, 'plural' => $name . 'Collection'];
        $request['schema']['entities']['Shadow'] = $shadow;
        try {
            generated($request, fn () => throw new LogicException('Collision was accepted: ' . $name));
            throw new LogicException('Collision was accepted: ' . $name);
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), $name) && str_contains($error->getMessage(), 'duplicated'), 'Expected a generated-type collision error: ' . $error->getMessage());
        }
    }
    echo "PASS: every automatic type from actual runtime assembly is reserved by generation\n";
});
