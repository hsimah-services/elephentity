<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use DateTimeImmutable;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WPGraphQL\Conformance\ConformanceChecker;
use Eleph\WPGraphQL\Integration\WpGraphQL;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use Eleph\WPGraphQL\Registration\TypeRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(TypeRegistrar::class)]
#[CoversClass(ConformanceChecker::class)]
final class RegistrarAndConformanceTest extends TestCase
{
    private static ?Manifest $manifest = null;

    public function testEnumConfigsCarryTheStoredValueBehindTheGraphQLName(): void
    {
        $configs = (new TypeRegistrar($this->manifest(), new FakeGateway()))->enumConfigs();

        self::assertSame(
            ['value' => 'draft'],
            $configs['PostStatus']['values']['DRAFT'],
        );
    }

    public function testObjectConfigsCarryNonNullTypesInWPGraphQLsNestedForm(): void
    {
        $fields = $this->postFields();

        self::assertSame(['non_null' => 'String'], $fields['title']['type']);
        self::assertSame('Int', $fields['price']['type']);
    }

    public function testAFieldResolverCallsTheAccessorTheGeneratorEmitted(): void
    {
        // The convention, executable.
        $resolve = $this->postFields()['title']['resolve'];

        self::assertIsCallable($resolve);

        $source = new class () {
            public function getTitle(): string
            {
                return 'Hello';
            }
        };

        self::assertSame('Hello', $resolve($source));
    }

    public function testADatetimeIsFormattedRatherThanHandedOverAsAnObject(): void
    {
        // The manifest says String and the accessor returns DateTimeImmutable. Without
        // an encoding step every query selecting a timestamp fails outright, which is
        // every entity using the shipped Timestamps pattern.
        $resolve = $this->postFields()['createdAt']['resolve'];

        self::assertIsCallable($resolve);

        $source = new class () {
            public function getCreatedAt(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-07T11:17:00+00:00');
            }
        };

        self::assertSame('2026-09-07T11:17:00+00:00', $resolve($source));
    }

    public function testABackedEnumTravelsAsTheValueTheEnumTypeMaps(): void
    {
        // register_graphql_enum_type maps DRAFT => 'draft'. Handing it the case object
        // matches nothing.
        $resolve = $this->postFields()['status']['resolve'];

        self::assertIsCallable($resolve);

        $source = new class () {
            public function getStatus(): FixtureStatus
            {
                return FixtureStatus::Draft;
            }
        };

        self::assertSame('draft', $resolve($source));
    }

    public function testNullShortCircuitsBeforeAnyEncoding(): void
    {
        $resolve = $this->postFields()['updatedAt']['resolve'];

        self::assertIsCallable($resolve);

        $source = new class () {
            public function getUpdatedAt(): mixed
            {
                return null;
            }
        };

        self::assertNull($resolve($source));
    }

    public function testADeclaredValueTypeIsUnwoundByItsWriteProcessor(): void
    {
        // Money is a Money on the entity and an Int over the wire. The processor that
        // already owns that conversion for writes owns it here too.
        $registrar = new TypeRegistrar(
            $this->manifest(),
            new FakeGateway(),
            processors: new FakeProcessors(),
        );

        $config = $registrar->objectConfigs()['Post'];
        self::assertIsArray($config['fields']);

        /** @var array<string, array<string, mixed>> $fields */
        $fields = $config['fields'];
        $resolve = $fields['price']['resolve'];

        self::assertIsCallable($resolve);

        $source = new class () {
            public function getPrice(): object
            {
                return new class () {
                    public int $cents = 1250;
                };
            }
        };

        self::assertSame(1250, $resolve($source));
    }

    public function testAValueTypeWithNoRegistrySaysWhichFieldNeedsOne(): void
    {
        // Silently handing WPGraphQL an object it cannot serialise would surface as a
        // scalar error naming the type, not the field. Name the field.
        $resolve = $this->postFields()['price']['resolve'];

        self::assertIsCallable($resolve);

        $source = new class () {
            public function getPrice(): object
            {
                return new stdClass();
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/"price".*Money/');

        $resolve($source);
    }

    public function testConnectionsAreRegisteredFromAndToTheRightTypes(): void
    {
        $comments = $this->connection('comments', 'Post');

        self::assertSame('Post', $comments['fromType']);
        self::assertSame('Comment', $comments['toType']);
    }

    public function testARootCollectionIsAConnectionRatherThanAList(): void
    {
        // A table needs to page and to say "1-20 of 347". A list_of with first/after
        // args would offer the arguments and neither of the answers.
        $posts = $this->connection('posts');

        self::assertSame('RootQuery', $posts['fromType']);
        self::assertSame('Post', $posts['toType']);
    }

    public function testEveryConnectionCarriesATotalCount(): void
    {
        // WPGraphQL supplies pageInfo, edges and nodes; not this. The lazy query counts
        // without hydrating, so it costs one query rather than the whole set.
        foreach ([['posts', 'RootQuery'], ['comments', 'Post']] as [$field, $from]) {
            $fields = $this->connection($field, $from)['connectionFields'];

            self::assertIsArray($fields);
            self::assertArrayHasKey('totalCount', $fields);
        }
    }

    public function testRootFieldsCoverBothOneAndMany(): void
    {
        $names = array_column((new TypeRegistrar($this->manifest(), new FakeGateway()))->rootFieldConfigs(), 'name');

        self::assertContains('post', $names, 'one by id');
        self::assertNotContains('posts', $names, 'the collection is a connection, not a field');
    }

    /**
     * @return array<string, mixed>
     */
    private function connection(string $field, string $from = 'RootQuery'): array
    {
        foreach ((new TypeRegistrar($this->manifest(), new FakeGateway()))->connectionConfigs() as $config) {
            // Qualified by the type it hangs off: an inverse can give an entity a
            // connection of the same name as a root one.
            if ($field === $config['fromFieldName'] && $from === $config['fromType']) {
                return $config;
            }
        }

        self::fail(sprintf('No connection registered for %s.%s.', $from, $field));
    }

    public function testConformanceFailsLoudlyWhenTheEntityClassIsMissing(): void
    {
        // The case the IR cannot see: a spec that changed and code that was not
        // regenerated.
        $problems = (new ConformanceChecker(
            $this->manifest(),
            static fn (string $entity): string => 'Nonexistent\\' . $entity,
        ))->check();

        self::assertNotSame([], $problems);
        self::assertStringContainsString('Run `eleph generate`', $problems[0]);
    }

    public function testConformanceFailsWhenAnAccessorIsMissing(): void
    {
        $problems = (new ConformanceChecker(
            $this->manifest(),
            static fn (): string => ConformanceSubject::class,
        ))->check();

        $joined = implode("\n", $problems);

        // The subject has getTitle() but not getPrice().
        self::assertStringNotContainsString('Post.title resolves', $joined);
        self::assertStringContainsString('Post.price resolves', $joined);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function postFields(): array
    {
        $config = (new TypeRegistrar($this->manifest(), new FakeGateway()))->objectConfigs()['Post'];

        self::assertIsArray($config['fields']);

        /** @var array<string, array<string, mixed>> $fields */
        $fields = $config['fields'];

        return $fields;
    }

    private function manifest(): Manifest
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }

        $compiled = (new SchemaCompiler(integrations: new IntegrationRegistry(WpGraphQL::definition())))->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return self::$manifest = (new ManifestBuilder())->build($compiled->schema());
    }
}
