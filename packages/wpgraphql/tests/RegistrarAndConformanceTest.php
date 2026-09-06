<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WPGraphQL\Conformance\ConformanceChecker;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use Eleph\WPGraphQL\Registration\TypeRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeRegistrar::class)]
#[CoversClass(ConformanceChecker::class)]
final class RegistrarAndConformanceTest extends TestCase
{
    private static ?Manifest $manifest = null;

    public function testEnumConfigsCarryTheStoredValueBehindTheGraphQLName(): void
    {
        $configs = (new TypeRegistrar($this->manifest()))->enumConfigs();

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

    public function testConnectionsAreRegisteredFromAndToTheRightTypes(): void
    {
        $configs = (new TypeRegistrar($this->manifest()))->connectionConfigs();

        $comments = array_values(array_filter(
            $configs,
            static fn (array $config): bool => 'comments' === $config['fromFieldName'],
        ));

        self::assertCount(1, $comments);
        self::assertSame('Post', $comments[0]['fromType']);
        self::assertSame('Comment', $comments[0]['toType']);
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
        $config = (new TypeRegistrar($this->manifest()))->objectConfigs()['Post'];

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

        $compiled = (new SchemaCompiler())->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return self::$manifest = (new ManifestBuilder())->build($compiled->schema());
    }
}
