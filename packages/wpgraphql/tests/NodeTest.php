<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\WPGraphQL\Manifest\GraphQLType;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use Eleph\WPGraphQL\Manifest\MutationEntry;
use Eleph\WPGraphQL\Manifest\ObjectTypeEntry;
use Eleph\WPGraphQL\Manifest\RootFieldEntry;
use Eleph\WPGraphQL\Registration\MutationRegistrar;
use Eleph\WPGraphQL\Registration\TypeRegistrar;
use Eleph\WPGraphQL\Relay\GlobalId;
use Eleph\WPGraphQL\Relay\NodeRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Node interface, end to end and without WordPress.
 *
 * Node is four things that have to agree — the interface is declared, the id is
 * globally unique, a node resolves back from that id, and the type it resolves to is
 * identifiable — and three of them are silent when broken. A type declaring Node and
 * handing out a row number still answers every query it did before; it only fails once
 * a client caches two of them.
 */
#[CoversClass(NodeRuntime::class)]
#[CoversClass(TypeRegistrar::class)]
#[CoversClass(MutationRegistrar::class)]
#[CoversClass(ObjectTypeEntry::class)]
#[CoversClass(ManifestBuilder::class)]
final class NodeTest extends TestCase
{
    public function testEveryTypeDeclaresNode(): void
    {
        foreach ($this->registrar()->objectConfigs() as $name => $config) {
            self::assertSame(['Node'], $config['interfaces'], $name);
        }
    }

    public function testIdIsGlobalAndDatabaseIdIsTheRow(): void
    {
        $fields = $this->postFields();
        $source = new class () {
            public function getId(): EntityId
            {
                return EntityId::of(12);
            }
        };

        $id = $fields['id']['resolve'];
        $databaseId = $fields['databaseId']['resolve'];

        self::assertIsCallable($id);
        self::assertIsCallable($databaseId);

        self::assertSame(GlobalId::encode('Post', 12), $id($source));
        self::assertSame('12', $databaseId($source));
    }

    public function testTheRootFieldAcceptsTheIdItHandedOut(): void
    {
        // The round trip a client actually performs: read `id`, ask for it back.
        $gateway = new FakeGateway();
        $resolve = $this->rootField('post', $gateway)['resolve'];

        self::assertIsCallable($resolve);

        $resolve(null, ['id' => GlobalId::encode('Post', 12)]);

        self::assertSame(['find Post#12'], $gateway->calls);
    }

    public function testTheRootFieldStillAcceptsARawRowId(): void
    {
        $gateway = new FakeGateway();
        $resolve = $this->rootField('post', $gateway)['resolve'];

        self::assertIsCallable($resolve);

        $resolve(null, ['id' => '12']);

        self::assertSame(['find Post#12'], $gateway->calls);
    }

    public function testNodeResolvesBackToTheRowItNames(): void
    {
        $gateway = new FakeGateway();
        $gateway->items = [new ConformanceSubject()];

        NodeRuntime::bind($this->manifest(), $gateway);

        $node = NodeRuntime::load('Post:12');

        self::assertNotNull($node);
        self::assertSame(['find Post#12'], $gateway->calls);

        // And the concrete type is then identifiable, which is what stops the query
        // failing with "No type was found matching the node" after the loader wins.
        self::assertSame('Post', NodeRuntime::typeOf($node));
    }

    public function testAnUnknownTypeResolvesToNothingRatherThanAnError(): void
    {
        // `node` is nullable, and a client guessing at ids should get nothing back
        // rather than an error naming every type the schema has.
        $gateway = new FakeGateway();
        NodeRuntime::bind($this->manifest(), $gateway);

        self::assertNull(NodeRuntime::load('NotAType:12'));
        self::assertNull(NodeRuntime::load('nodelimiter'));
        self::assertSame([], $gateway->calls);
    }

    public function testAMutationTakesTheIdTheTypeHandedOut(): void
    {
        $gateway = new FakeGateway();
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();
        $mutate = $configs['updatePost']['mutateAndGetPayload'];

        self::assertIsCallable($mutate);

        $mutate(['id' => GlobalId::encode('Post', 12), 'title' => 'Hello']);

        self::assertSame(['update Post#12(title)'], $gateway->calls);
    }

    public function testAnEdgeIsWrittenWithTheIdTheTargetHandedOut(): void
    {
        // An edge input is an ID, and the id a client holds for the row it points at
        // is the global one. Passed through untouched it would link to nothing.
        $gateway = new FakeGateway();

        $registrar = new MutationRegistrar(
            new Manifest(
                objects: ['Post' => new ObjectTypeEntry('Post', 'Post', [])],
                mutations: [
                    'createPost' => new MutationEntry(
                        'createPost',
                        MutationEntry::CREATE,
                        'Post',
                        ['author' => new GraphQLType('ID'), 'title' => new GraphQLType('String')],
                    ),
                ],
                roots: ['Post' => new RootFieldEntry('Post', 'Posts', 'Post')],
            ),
            $gateway,
        );

        $mutate = $registrar->configs()['createPost']['mutateAndGetPayload'];

        self::assertIsCallable($mutate);

        $mutate(['author' => GlobalId::encode('Author', 7), 'title' => 'Hello']);

        self::assertSame(['author' => '7', 'title' => 'Hello'], $gateway->input);
    }

    public function testADeletedIdComesBackInTheFormTheClientCachedIt(): void
    {
        $gateway = new FakeGateway();
        $configs = (new MutationRegistrar($this->manifest(), $gateway))->configs();
        $mutate = $configs['deletePost']['mutateAndGetPayload'];

        self::assertIsCallable($mutate);

        self::assertSame(
            ['deletedId' => GlobalId::encode('Post', 12)],
            $mutate(['id' => GlobalId::encode('Post', 12)]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rootField(string $name, FakeGateway $gateway): array
    {
        foreach ((new TypeRegistrar($this->manifest(), $gateway))->rootFieldConfigs() as $config) {
            if ($name === $config['name']) {
                return $config['field'];
            }
        }

        self::fail(sprintf('No root field named %s.', $name));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function postFields(): array
    {
        $config = $this->registrar()->objectConfigs()['Post'];

        self::assertIsArray($config['fields']);

        /** @var array<string, array<string, mixed>> $fields */
        $fields = $config['fields'];

        return $fields;
    }

    private function registrar(): TypeRegistrar
    {
        return new TypeRegistrar($this->manifest(), new FakeGateway());
    }

    private function manifest(): Manifest
    {
        return Fixture::manifest();
    }
}
