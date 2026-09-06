<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use Eleph\WPGraphQL\Manifest\MutationEntry;
use Eleph\WPGraphQL\Manifest\TypeMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManifestBuilder::class)]
#[CoversClass(TypeMapper::class)]
final class ManifestBuilderTest extends TestCase
{
    private static ?Schema $schema = null;

    private static ?Manifest $manifest = null;

    public function testEveryEntityBecomesAnObjectType(): void
    {
        self::assertSame(['Comment', 'Post', 'Tag'], array_keys($this->manifest()->objects));
    }

    public function testASpecFieldMapsToAGetterByConvention(): void
    {
        // The convention the whole plugin layer rests on: field `title` → `getTitle()`.
        // Both ends came from the same spec, so they cannot drift.
        $post = $this->manifest()->objects['Post'];

        self::assertSame('title', $post->fields['title']->name);
        self::assertSame('getTitle', $post->fields['title']->accessor);
        self::assertSame('String', $post->fields['title']->type->name);
        self::assertTrue($post->fields['title']->type->nonNull);
    }

    public function testTheImplicitIdIsExposedAsAnOpaqueIdNotAnInt(): void
    {
        // So that a later move to UUIDv7 is invisible to clients.
        $id = $this->manifest()->objects['Post']->fields['id'];

        self::assertSame('ID', $id->type->name);
        self::assertTrue($id->type->nonNull);
        self::assertSame('getId', $id->accessor);
    }

    public function testNullabilityFollowsTheSpecRatherThanRequiredness(): void
    {
        // `required` describes creating a row, not reading one.
        $post = $this->manifest()->objects['Post'];

        self::assertFalse($post->fields['price']->type->nonNull);
        self::assertTrue($post->fields['createdAt']->type->nonNull);
    }

    public function testAValueTypeTravelsAsItsBackingPrimitive(): void
    {
        // Money is an int over the wire; a bespoke scalar would add a conversion the
        // spec cannot describe and the write processor already owns.
        self::assertSame('Int', $this->manifest()->objects['Post']->fields['price']->type->name);
    }

    public function testDeclaredAndInlineEnumsBothBecomeGraphQLEnums(): void
    {
        $enums = $this->manifest()->enums;

        self::assertArrayHasKey('PostStatus', $enums);
        self::assertArrayHasKey('PostVisibility', $enums);

        // Clients send SCREAMING_SNAKE_CASE; the stored value is untouched.
        self::assertSame(
            ['DRAFT' => 'draft', 'SCHEDULED' => 'scheduled', 'PUBLISHED' => 'published'],
            $enums['PostStatus']->values,
        );
    }

    public function testToManyEdgesBecomeConnectionsAndToOneBecomeFields(): void
    {
        $post = $this->manifest()->objects['Post'];

        self::assertArrayHasKey('comments', $post->connections);
        self::assertSame('Comment', $post->connections['comments']->toType);
        self::assertSame('comments', $post->connections['comments']->accessor);

        // A connection is not also a plain field.
        self::assertArrayNotHasKey('comments', $post->fields);
    }

    public function testEveryEntityGetsCreateAndUpdateMutations(): void
    {
        $mutations = $this->manifest()->mutations;

        self::assertArrayHasKey('createPost', $mutations);
        self::assertArrayHasKey('updatePost', $mutations);
        self::assertSame(MutationEntry::CREATE, $mutations['createPost']->kind);
    }

    public function testAnImmutableFieldIsAbsentFromUpdateButPresentOnCreate(): void
    {
        // The same rule the mutator enforces by generating no setter.
        $mutations = $this->manifest()->mutations;

        self::assertArrayHasKey('createdAt', $mutations['createPost']->inputs);
        self::assertArrayNotHasKey('createdAt', $mutations['updatePost']->inputs);
    }

    public function testARequiredFieldIsNonNullOnCreateAndNullableOnUpdate(): void
    {
        $mutations = $this->manifest()->mutations;

        self::assertTrue($mutations['createPost']->inputs['title']->nonNull);
        // An update supplies only what changes.
        self::assertFalse($mutations['updatePost']->inputs['title']->nonNull);
    }

    public function testEachActionBecomesItsOwnMutationTakingItsArguments(): void
    {
        // An action's inputs are its declared arguments, not its writes: writes say
        // what it may change, arguments say what the caller supplies.
        $publish = $this->manifest()->mutations['publishPost'];

        self::assertSame(MutationEntry::ACTION, $publish->kind);
        self::assertSame('publish', $publish->method);
        self::assertSame(['id', 'at'], array_keys($publish->inputs));
        self::assertFalse($publish->inputs['at']->nonNull);
        self::assertArrayNotHasKey('status', $publish->inputs);
    }

    public function testTheManifestIsDeterministic(): void
    {
        $first = (new ManifestBuilder())->build($this->schema());
        $second = (new ManifestBuilder())->build($this->schema());

        self::assertEquals($first, $second);
    }

    private function manifest(): Manifest
    {
        return self::$manifest ??= (new ManifestBuilder())->build($this->schema());
    }

    private function schema(): Schema
    {
        if (null !== self::$schema) {
            return self::$schema;
        }

        $compiled = (new SchemaCompiler())->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return self::$schema = $compiled->schema();
    }
}
