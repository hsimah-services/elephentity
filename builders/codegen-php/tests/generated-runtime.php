<?php

declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/golden/runtime-contracts/Amount.php';

use Eleph\Memory\MemoryAdaptor;
use Eleph\Runtime\Gateway\Runtime;
use Eleph\Runtime\Gateway\UnitOfWorkFactory;
use Eleph\Runtime\Mutation\ActionCall;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Policy\AnonymousViewerProvider;
use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Policy\WriteGate;
use Eleph\Runtime\Query\CachingEdgeLoader;
use Eleph\Runtime\Query\Queries;
use Eleph\Runtime\Query\ValueDecoder;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Verification\CommitRejected;
use Eleph\Runtime\Verification\Verification;
use Eleph\Runtime\Verification\Violation;
use Psr\Container\ContainerInterface;
use RuntimeCheck\Enum\Status;
use RuntimeCheck\Page\Page;
use RuntimeCheck\Page\PageMutationContext;

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$request = file_get_contents(__DIR__ . '/fixtures/golden/runtime-contracts/request.json');
$input = tmpfile();
fwrite($input, $request);
rewind($input);
$output = tmpfile();
$process = proc_open([dirname(__DIR__) . '/bin/eleph-gen-php'], [$input, $output, STDERR], $pipes);
check(is_resource($process) && 0 === proc_close($process), 'Generation failed.');
rewind($output);
$response = json_decode(stream_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
$directory = sys_get_temp_dir() . '/eleph-generated-' . bin2hex(random_bytes(6));
mkdir($directory);
try {
    foreach ($response['files'] as $file) {
        $path = $directory . '/' . $file['path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\n\n" . $file['body']);
    }
    spl_autoload_register(static function (string $class) use ($directory): void {
        if (str_starts_with($class, 'RuntimeCheck\\')) {
            require $directory . '/' . str_replace('\\', '/', substr($class, strlen('RuntimeCheck\\'))) . '.php';
        }
    });
    $container = new class () implements ContainerInterface {
        public array $services = [];
        public array $factories = [];
        public function has(string $id): bool
        {
            return isset($this->services[$id]) || isset($this->factories[$id]);
        }
        public function get(string $id): object
        {
            return $this->services[$id] ??= ($this->factories[$id])($this);
        }
    };
    $container->factories = RuntimeCheck\Wiring::registrations();
    $container->services[ValueDecoder::class] = new ValueDecoder();
    $container->services[RuntimeCheck\Page\Contract\PagePublicReadPolicy::class] = new class () implements RuntimeCheck\Page\Contract\PagePublicReadPolicy {
        public function decide(Page $entity, Eleph\Runtime\Policy\Viewer $viewer): Eleph\Runtime\Policy\PolicyDecision
        {
            return Eleph\Runtime\Policy\PolicyDecision::allow();
        }
    };
    $container->services[RuntimeCheck\Page\Contract\PagePublishedAtVerifier::class] = new class () implements RuntimeCheck\Page\Contract\PagePublishedAtVerifier {
        public function verify(?DateTimeImmutable $value, PageMutationContext $context): Verification
        {
            return (null !== $context->originalPublishedAt() && $value != $context->originalPublishedAt() || Status::Published === $context->pendingStatus() && null === $value)
                ? Verification::failed(new Violation('publishedAt.retained', 'Retain the first publication timestamp.')) : Verification::ok();
        }
    };
    $container->services[RuntimeCheck\Page\Contract\PagePublishAction::class] = new class () implements RuntimeCheck\Page\Contract\PagePublishAction {
        public function handle(RuntimeCheck\Page\PagePublishContext $context, DateTimeImmutable $at): void
        {
            $read = $context->context();
            check($read->id()->isPersisted() && 'Page' === $read->entity(), 'Action read context lost identity.');
            check(!method_exists($read, 'set') && !method_exists($read, 'setTitle') && !method_exists($context, 'setTitle'), 'Action exposes undeclared writes.');
            $context->setStatus(Status::Published)->setPublishedAt($read->originalPublishedAt() ?? $read->pendingPublishedAt() ?? $at);
        }
    };
    $container->services[RuntimeCheck\Page\Contract\PageEnquiryUrlVerifier::class] = new class () implements RuntimeCheck\Page\Contract\PageEnquiryUrlVerifier {
        public function verify(?string $value, PageMutationContext $context): Verification
        {
            return Verification::ok();
        }
    };
    $container->services[RuntimeCheck\Type\AmountReadProcessor::class] = new class () implements RuntimeCheck\Type\AmountReadProcessor {
        public function read(mixed $value): RuntimeCheckType\Amount
        {
            return new RuntimeCheckType\Amount(json_decode($value, true, 512, JSON_THROW_ON_ERROR)['cents']);
        }
    };
    $container->services[RuntimeCheck\Type\AmountWriteProcessor::class] = new class () implements RuntimeCheck\Type\AmountWriteProcessor {
        public function write(mixed $value): string
        {
            return json_encode(['cents' => $value->cents], JSON_THROW_ON_ERROR);
        }
        public function verify(mixed $value, Eleph\Runtime\Mutation\MutationContext $context): Verification
        {
            return $value->cents < 0 ? Verification::failed(new Violation('amount.negative', 'Amount must be non-negative.')) : Verification::ok();
        }
    };
    $processors = new class ($container) implements Eleph\Runtime\Type\ProcessorRegistry {
        public function __construct(private ContainerInterface $container)
        {
        }
        public function has(string $type): bool
        {
            return 'Amount' === $type;
        }
        public function read(string $type): Eleph\Runtime\Type\ReadProcessor
        {
            return $this->container->get(RuntimeCheck\Type\AmountReadProcessor::class);
        }
        public function write(string $type): Eleph\Runtime\Type\WriteProcessor
        {
            return $this->container->get(RuntimeCheck\Type\AmountWriteProcessor::class);
        }
    };
    $storage = new MemoryAdaptor();
    if ('sqlite' === ($argv[2] ?? '')) {
        $database = new Eleph\SQLite\Database(':memory:');
        $install = require __DIR__ . '/fixtures/golden/runtime-contracts/sqlite/install.php';
        $install($database->pdo, 'test_');
        $manifest = (require __DIR__ . '/fixtures/golden/runtime-contracts/sqlite/storage-manifest.php')->withPrefix('test_');
        $storage = new Eleph\SQLite\SQLiteAdaptor($database, $manifest->tables, new Eleph\SQLite\Sql\FieldMap($manifest->columns), $manifest->placements);
    }
    $catalogue = new RuntimeCheck\Catalogue($container);
    $viewer = new AnonymousViewerProvider();
    $reads = new ReadGate($catalogue, $viewer);
    $runtime = new Runtime($storage, $catalogue, new UnitOfWorkFactory($storage, $catalogue, $processors), $reads, new WriteGate($catalogue, $viewer));
    $queries = new Queries($storage, new CachingEdgeLoader($storage, $runtime, [], $reads), $reads);
    $container->services[RuntimeCheck\Page\Contract\PageSearchQuery::class] = new class ($queries, $container->get(RuntimeCheck\Page\PageHydrator::class)) implements RuntimeCheck\Page\Contract\PageSearchQuery {
        public function __construct(private Queries $queries, private RuntimeCheck\Page\PageHydrator $hydrator)
        {
        }
        public function find(?int $limit = null): Eleph\Runtime\Query\EntityQuery
        {
            return $this->queries->of($this->hydrator, Criteria::for('Page')->where(Filter::equals('status', 'published')));
        }
    };
    $container->services[RuntimeCheck\Page\Contract\PageBySlugQuery::class] = new class ($queries, $container->get(RuntimeCheck\Page\PageHydrator::class)) implements RuntimeCheck\Page\Contract\PageBySlugQuery {
        public function __construct(private Queries $queries, private RuntimeCheck\Page\PageHydrator $hydrator)
        {
        }
        public function find(string $slug): ?Page
        {
            $page = $this->queries->of($this->hydrator, Criteria::for('Page')->where(Filter::equals('slug', $slug))->where(Filter::equals('status', 'published')))->first();
            check(null === $page || $page instanceof Page, 'Finder received the wrong entity.');
            return $page;
        }
    };
    $user = $runtime->create('User', ['username' => 'admin'])->entity;
    check(RuntimeCheck\Enum\Role::Editor === $user->getRole() && $user->getActive(), 'User defaults or integer-backed enum were not applied.');
    $result = $runtime->create('Page', ['title' => 'Title', 'slug' => 'visible']);
    $page = $result->entity;
    check($page instanceof Page, 'Create did not return the generated entity.');
    check(Status::Draft === $page->getStatus() && '' === $page->getContent() && '' === $page->getExcerpt(), 'Declared defaults were not applied.');
    check($page->getActive() && !$page->getDisabled() && 0 === $page->getZero() && ['source' => 'default'] === $page->getMetadata(), 'Falsy or structured defaults were lost.');
    check(10 === $page->getAmount()->cents, 'Custom JSON-backed default was not decoded by its processor.');
    check('2000' !== $page->getCreatedAt()->format('Y'), 'Input default overrode a managed field.');
    check(null === $runtime->runQuery('Page', 'bySlug', ['slug' => 'visible']) && null === $runtime->runQuery('Page', 'bySlug', ['slug' => 'absent']), 'Draft or absent singular query leaked a row.');
    $runtime->update('Page', $result->id, ['content' => 'Retained', 'enquiryUrl' => 'https://example.com']);
    $page = $runtime->update('Page', $result->id, ['title' => 'Changed', 'enquiryUrl' => null])->entity;
    check('Retained' === $page->getContent() && null === $page->getEnquiryUrl(), 'Partial update reset omitted fields or rejected optional clearing.');
    foreach ([['status' => 'published'], ['title' => null], ['content' => null], ['amount' => '{"cents":-1}']] as $input) {
        try {
            $runtime->update('Page', $result->id, $input);
            throw new LogicException('Invalid input committed.');
        } catch (CommitRejected) {
        }
    }
    $before = $storage->count(Criteria::for('Page'));
    try {
        $runtime->create('Page', ['title' => null, 'slug' => 'invalid']);
        throw new LogicException('Invalid create committed.');
    } catch (CommitRejected) {
    }
    check($storage->count(Criteria::for('Page')) === $before, 'Rejected create inserted a row.');
    $at = new DateTimeImmutable('2026-10-10T12:00:00Z');
    $later = $at->modify('+1 day');
    $runtime->runActions('Page', $result->id, [new ActionCall('publish', ['at' => $at]), new ActionCall('publish', ['at' => $later])]);
    check($runtime->find('Page', $result->id)->getPublishedAt() == $at, 'Repeat actions in one mutation lost the first timestamp.');
    $runtime->runAction('Page', 'publish', $result->id, ['at' => $later]);
    $runtime->update('Page', $result->id, ['status' => 'draft']);
    $runtime->runAction('Page', 'publish', $result->id, ['at' => $later]);
    check($runtime->find('Page', $result->id)->getPublishedAt() == $at, 'Republishing overwrote the original timestamp.');
    check($runtime->runQuery('Page', 'bySlug', ['slug' => 'visible']) instanceof Page, 'Found singular query failed.');
    try {
        $runtime->update('Page', $result->id, ['publishedAt' => null]);
        throw new LogicException('Timestamp clearing committed.');
    } catch (CommitRejected) {
    }
    check($runtime->find('Page', $result->id)->getPublishedAt() == $at, 'Rejected update was not atomic.');
    $explicit = $runtime->create('Page', ['title' => 'Other', 'slug' => 'other', 'active' => false, 'zero' => 7, 'content' => 'Explicit'])->entity;
    check(!$explicit->getActive() && 7 === $explicit->getZero() && 'Explicit' === $explicit->getContent(), 'Explicit inputs did not override defaults.');
    $manifest = require __DIR__ . '/fixtures/golden/runtime-contracts/graphql-manifest.php';
    $builder = new Eleph\GraphQL\SchemaBuilder();
    $builder->interface('Node', ['id' => ['type' => ['non_null' => 'ID']]], fn () => $builder->type('Page'));
    $builder->object('PageInfo', ['fields' => ['hasNextPage' => ['type' => 'Boolean'], 'hasPreviousPage' => ['type' => 'Boolean'], 'startCursor' => ['type' => 'String'], 'endCursor' => ['type' => 'String']]]);
    $registrar = new Eleph\GraphQL\Registration\TypeRegistrar($manifest, $runtime);
    foreach ($registrar->enumConfigs() as $name => $config) {
        $builder->enum($name, $config);
    }
    foreach ($registrar->objectConfigs() as $name => $config) {
        $builder->object($name, $config);
    }
    foreach ($registrar->rootFieldConfigs() as $config) {
        $builder->field('RootQuery', $config['name'], $config['field']);
    }
    foreach ($registrar->connectionConfigs() as $config) {
        $builder->connection($config);
    }
    $schema = $builder->build();
    $schema->assertValid();
    foreach (['visible' => ['title' => 'Changed'], 'other' => null, 'absent' => null] as $slug => $expected) {
        $response = GraphQL\GraphQL::executeQuery($schema, 'query($slug: String!) { pageBySlug(slug: $slug) { title } }', variableValues: ['slug' => $slug])->toArray();
        check($response === ['data' => ['pageBySlug' => $expected]], 'Generated singular GraphQL query failed: ' . json_encode($response));
    }
    $response = GraphQL\GraphQL::executeQuery($schema, '{ searchPages(first: 10, where: {limit: 20}) { nodes {title} } }')->toArray();
    check($response === ['data' => ['searchPages' => ['nodes' => [['title' => 'Changed']]]]], 'Generated collection query regressed: ' . json_encode($response));
    if ('sqlite' === ($argv[2] ?? '')) {
        $database->execute('CREATE INDEX rank_reverse_id ON test_page(rank, id DESC)');
        $database->execute('PRAGMA reverse_unordered_selects = ON');
        $expected = [];
        for ($i = 0; $i < 250; ++$i) {
            $created = $runtime->create('Page', ['title' => 'Bulk', 'slug' => 'bulk-' . $i, 'rank' => 101 + $i % 3]);
            $expected[] = ['id' => (int) $created->id->raw(), 'rank' => 101 + $i % 3];
        }
        usort($expected, static fn (array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: ($a['id'] <=> $b['id']));
        $query = $queries->of($container->get(RuntimeCheck\Page\PageHydrator::class), Criteria::for('Page')->where(Filter::greaterThan('rank', 100))->orderBy(Eleph\Runtime\Storage\Order::ascending('rank')));
        check(250 === $query->count(), 'Gated chunked SQLite count lost tied rows.');
        $seen = [];
        $cursor = null;
        for ($step = 0; $step < 20; ++$step) {
            $page = $query->page(17, $cursor);
            foreach ($page->items as $item) {
                $seen[] = (int) $item->getId()->raw();
            }
            $cursor = $page->next;
            if (null === $cursor) {
                break;
            }
        }
        check($seen === array_column($expected, 'id') && null === $cursor, 'Gated chunked SQLite pages lost or repeated tied rows.');
    }
    $mutation = new Mutation('Page', new Eleph\Runtime\Identity\PendingId('Page'), actions: [new ActionCall('create', [])]);
    $mutation->set('content', 'Action value');
    $catalogue->apply('Page', $mutation, ['title' => 'Action', 'slug' => 'action']);
    check(Status::Draft === $mutation->pending('status') && 'Action value' === $mutation->pending('content'), 'Action creation did not preserve pending values and seed defaults.');
    echo "PASS: generated defaults, partial updates, nullable final-state verification, atomic rejection and singular finder results\n";
} finally {
    $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($paths as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($directory);
}
