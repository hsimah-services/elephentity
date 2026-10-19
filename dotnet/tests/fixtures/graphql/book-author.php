<?php

declare(strict_types=1);

// Builds the schema elephentity-graphql-php produces for a small book/author model and prints its introspection as JSON.
// The model is the C# test manifest's twin (tests/Elephentity.GraphQL.Tests/BookModel.cs): change one, change the other.
//
//   php book-author.php <vendor/autoload.php> <elephentity-graphql-php/src>   > book-author.introspection.json
//
// tests/fixtures/graphql/regenerate.sh runs it in the PHP container and normalises the result with graphql-js.

require $argv[1];
spl_autoload_register(static function (string $class) use ($argv): void {
    $prefix = 'Eleph\\GraphQL\\';
    if (str_starts_with($class, $prefix)) {
        require $argv[2] . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
}, prepend: true);

use Eleph\GraphQL\Manifest\{ConnectionEntry, EnumTypeEntry, FieldEncoding, FieldEntry, GraphQLType, Manifest, MutationEntry, ObjectTypeEntry, QueryFieldEntry, RootFieldEntry};
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

$manifest = new Manifest(
    objects: [
        'Book' => new ObjectTypeEntry(
            'Book',
            'Book',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', nonNull: true), 'getId', 'The global id.', FieldEncoding::GlobalId),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', nonNull: true), 'getId', null, FieldEncoding::Id),
                'title' => new FieldEntry('title', new GraphQLType('String', nonNull: true), 'getTitle', 'What it is called.'),
                'status' => new FieldEntry('status', new GraphQLType('BookStatus', nonNull: true), 'getStatus', null, FieldEncoding::BackedEnum),
                'publishedAt' => new FieldEntry('publishedAt', new GraphQLType('String'), 'getPublishedAt', null, FieldEncoding::Datetime),
                'pages' => new FieldEntry('pages', new GraphQLType('Int'), 'getPages'),
                'rating' => new FieldEntry('rating', new GraphQLType('Float'), 'getRating'),
                'inPrint' => new FieldEntry('inPrint', new GraphQLType('Boolean', nonNull: true), 'getInPrint'),
                'tags' => new FieldEntry('tags', new GraphQLType('String'), 'getTags', null, FieldEncoding::Json),
            ],
            ['authors' => new ConnectionEntry('authors', 'Book', 'Author', 'authors', 'authors', 'Who wrote it.')],
            'A book.',
        ),
        'Author' => new ObjectTypeEntry(
            'Author',
            'Author',
            [
                'id' => new FieldEntry('id', new GraphQLType('ID', nonNull: true), 'getId', null, FieldEncoding::GlobalId),
                'databaseId' => new FieldEntry('databaseId', new GraphQLType('ID', nonNull: true), 'getId', null, FieldEncoding::Id),
                'name' => new FieldEntry('name', new GraphQLType('String', nonNull: true), 'getName'),
            ],
            ['books' => new ConnectionEntry('books', 'Author', 'Book', 'books', 'books')],
        ),
    ],
    enums: ['BookStatus' => new EnumTypeEntry('BookStatus', ['DRAFT' => 'draft', 'LIVE' => 'live'])],
    mutations: [
        'createBook' => new MutationEntry('createBook', MutationEntry::CREATE, 'Book', [
            'title' => new GraphQLType('String', nonNull: true),
            'status' => new GraphQLType('BookStatus', nonNull: true),
            'publishedAt' => new GraphQLType('String'),
            'authors' => new GraphQLType('ID', list: true),
        ], null, 'Create a book.'),
        'updateBook' => new MutationEntry('updateBook', MutationEntry::UPDATE, 'Book', [
            'id' => new GraphQLType('ID', nonNull: true),
            'title' => new GraphQLType('String'),
            'status' => new GraphQLType('BookStatus'),
        ]),
        'publishBook' => new MutationEntry('publishBook', MutationEntry::ACTION, 'Book', [
            'id' => new GraphQLType('ID', nonNull: true),
            'at' => new GraphQLType('String'),
        ], 'publish'),
        'createAuthor' => new MutationEntry('createAuthor', MutationEntry::CREATE, 'Author', [
            'name' => new GraphQLType('String', nonNull: true),
        ]),
    ],
    roots: [
        'Book' => new RootFieldEntry('Book', 'Books', 'Book'),
        'Author' => new RootFieldEntry('Author', 'Authors', 'Author'),
    ],
    queries: [
        'bookBySlug' => new QueryFieldEntry('bookBySlug', 'Book', false, 'Book', 'bySlug', ['slug' => new GraphQLType('String', nonNull: true)], 'One book by its slug.'),
        'searchBooks' => new QueryFieldEntry('searchBooks', 'Book', true, 'Book', 'search', ['text' => new GraphQLType('String'), 'status' => new GraphQLType('BookStatus')], 'Books matching a search.'),
    ],
);

// The assembly the PHP application does (clog's server/standalone/src/GraphQL.php, minus clog): Node, PageInfo and node(id:).
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
