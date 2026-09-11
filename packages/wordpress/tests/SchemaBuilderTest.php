<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Origin;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Ir\StorageDefinition;
use Eleph\Schema\Ir\TypeDefinition;
use Eleph\Schema\Ir\TypeReference;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\WordPress\Sql\Naming;
use Eleph\WordPress\Sql\SchemaBuilder;
use Eleph\WordPress\Sql\TableSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(Naming::class)]
final class SchemaBuilderTest extends TestCase
{
    private static ?Schema $schema = null;

    public function testEveryEntityGetsATableWithAnImplicitId(): void
    {
        $post = $this->table('wp_phe_post');

        $id = $post->column('id');

        self::assertNotNull($id);
        self::assertSame('BIGINT UNSIGNED', $id->type);
        self::assertTrue($id->autoIncrement);
        self::assertSame('id', $post->primaryKey);
    }

    public function testCamelCaseFieldsBecomeSnakeCaseColumns(): void
    {
        $post = $this->table('wp_phe_post');

        self::assertNotNull($post->column('created_at'));
        self::assertNotNull($post->column('post_id'));
        self::assertNull($post->column('createdAt'));
    }

    public function testColumnTypesFollowThePrimitive(): void
    {
        $post = $this->table('wp_phe_post');

        $title = $post->column('title');
        $createdAt = $post->column('created_at');
        $updatedAt = $post->column('updated_at');
        $postId = $post->column('post_id');
        $body = $this->table('wp_phe_comment')->column('body');

        self::assertNotNull($title);
        self::assertNotNull($createdAt);
        self::assertNotNull($updatedAt);
        self::assertNotNull($postId);
        self::assertNotNull($body);

        // maxLength: 200 in the spec.
        self::assertSame('VARCHAR(200)', $title->type);
        self::assertSame('DATETIME', $createdAt->type);
        self::assertSame('BIGINT', $postId->type);
        self::assertSame('LONGTEXT', $body->type);

        // required and nullable are separate facts, and only the second shapes the
        // column. A managed field is never null, because the framework fills it.
        self::assertFalse($createdAt->nullable);
        self::assertFalse($updatedAt->nullable);
        self::assertTrue($post->column('published_at')?->nullable);
    }

    public function testEnumColumnsAreSizedToTheirLongestMember(): void
    {
        // Sized from the spec rather than a fixed width, so adding a longer member
        // surfaces as a migration rather than being absorbed silently.
        $post = $this->table('wp_phe_post');

        // PostStatus: draft, scheduled, published — longest is 9.
        self::assertSame('VARCHAR(9)', $post->column('status')?->type);
        // Inline: public, private — longest is 7.
        self::assertSame('VARCHAR(7)', $post->column('visibility')?->type);
    }

    public function testADeclaredValueTypeStoresAsItsBackingPrimitive(): void
    {
        // price: Money, and Money's own spec declares `primitive: int`. Falling back
        // to LONGTEXT here would silently disagree with the generated PHP, which
        // resolves Money's backing type correctly.
        $post = $this->table('wp_phe_post');

        self::assertSame('BIGINT', $post->column('price')?->type);
    }

    public function testADeclaredEnumReferencedDirectlyIsSizedToItsLongestMember(): void
    {
        // `type: Status` (a bare declared-type reference) rather than the documented
        // `type: enum, values: Status` form. Both must resolve to the enum's own
        // members, not fall through to an unindexable LONGTEXT.
        $schema = $this->schemaWithDirectlyReferencedEnum();

        $tables = (new SchemaBuilder(new Naming()))->build($schema);

        self::assertArrayHasKey('post', $tables);
        self::assertSame('VARCHAR(9)', $tables['post']->column('status')?->type);
    }

    private function schemaWithDirectlyReferencedEnum(): Schema
    {
        $status = new TypeDefinition(
            name: 'Status',
            primitive: Primitive::String,
            sourceFile: 'types/Status.yml',
            values: ['draft', 'published'],
        );

        $field = new FieldDefinition(
            name: 'status',
            type: TypeReference::declared('Status'),
            origin: Origin::entity('entities/Post.yml'),
            required: true,
        );

        $entity = new EntityDefinition(
            name: 'Post',
            storage: new StorageDefinition('wordpress', 'post'),
            sourceFile: 'entities/Post.yml',
            fields: ['status' => $field],
        );

        return new Schema(
            project: new ProjectDefinition('test', 'wordpress', 'project.yml'),
            entities: ['Post' => $entity],
            types: ['Status' => $status],
        );
    }

    public function testATaxonomyBackedEntityGetsNoTableOfItsOwn(): void
    {
        $tables = (new SchemaBuilder(new Naming()))->build($this->schemaWithATaxonomy());

        self::assertArrayHasKey('tutorial', $tables);
        self::assertArrayNotHasKey('aka', $tables);
    }

    public function testAManyToManyEdgeToATaxonomyGetsNoColumnOrJoinTable(): void
    {
        // No FK column on Tutorial's own table, and no join table either — the
        // relationship lives in wp_term_relationships, which this builder never touches.
        $tables = (new SchemaBuilder(new Naming()))->build($this->schemaWithATaxonomy());

        self::assertNull($tables['tutorial']->column('akas_id'));
        self::assertCount(1, $tables, 'only the Tutorial table exists');
    }

    private function schemaWithATaxonomy(): Schema
    {
        $aka = new EntityDefinition(
            name: 'Aka',
            storage: new StorageDefinition('wordpress', 'aka', handle: 'aka'),
            sourceFile: 'entities/Aka.yml',
            fields: ['name' => new FieldDefinition('name', TypeReference::primitive(Primitive::String), Origin::entity('entities/Aka.yml'))],
            config: ['taxonomy' => true],
        );

        $tutorial = new EntityDefinition(
            name: 'Tutorial',
            storage: new StorageDefinition('wordpress', 'tutorial'),
            sourceFile: 'entities/Tutorial.yml',
            edges: ['akas' => new EdgeDefinition('akas', 'Aka', Cardinality::Many, Origin::entity('entities/Tutorial.yml'))],
        );

        return new Schema(
            project: new ProjectDefinition('test', 'wordpress', 'project.yml'),
            entities: ['Aka' => $aka, 'Tutorial' => $tutorial],
        );
    }

    public function testAOneToManyEdgePutsTheKeyOnTheFarSide(): void
    {
        // Post declares comments; the column lands on the comment table, named from
        // the reverse accessor.
        $comment = $this->table('wp_phe_comment');
        $column = $comment->column('post_id');

        self::assertNotNull($column);
        self::assertSame('BIGINT UNSIGNED', $column->type);
        self::assertTrue($column->nullable, 'a referencing row can exist before it is attached');
        self::assertArrayHasKey('wp_phe_comment_post_id_idx', $comment->indexes);

        self::assertNull($this->table('wp_phe_post')->column('comments_id'));
    }

    public function testAManyToManyEdgeDerivesAJoinTable(): void
    {
        $join = $this->table('wp_phe_post_tags');

        self::assertNotNull($join->column('post_id'));
        self::assertNotNull($join->column('tag_id'));
        self::assertSame('', $join->primaryKey, 'the pair is the identity, not a surrogate');

        $unique = $join->indexes['wp_phe_post_tags_pair_uniq'] ?? null;

        self::assertNotNull($unique);
        self::assertTrue($unique->unique);
        self::assertSame(['post_id', 'tag_id'], $unique->columns);
    }

    public function testASelfReferencingManyToManyEdgeGetsTwoDistinctColumns(): void
    {
        // Both sides of Person.friends resolve to Person, so joinColumn($entity->name)
        // and joinColumn($target->name) would otherwise both be "person_id" — one
        // column, silently, in an array keyed by column name. The edge name is what
        // has to disambiguate the target side.
        $tables = (new SchemaBuilder(new Naming()))->build($this->schemaWithASelfReferencingEdge());

        $join = $tables['person_friends'] ?? null;

        self::assertNotNull($join);
        self::assertCount(2, $join->columns, 'both sides of the pair must be real columns');
        self::assertNotNull($join->column('person_id'));
        self::assertNotNull($join->column('friends_id'));

        $unique = $join->indexes['person_friends_pair_uniq'] ?? null;

        self::assertNotNull($unique);
        self::assertSame(['person_id', 'friends_id'], $unique->columns);
    }

    private function schemaWithASelfReferencingEdge(): Schema
    {
        $person = new EntityDefinition(
            name: 'Person',
            storage: new StorageDefinition('wordpress', 'person'),
            sourceFile: 'entities/Person.yml',
            edges: ['friends' => new EdgeDefinition('friends', 'Person', Cardinality::Many, Origin::entity('entities/Person.yml'))],
        );

        return new Schema(
            project: new ProjectDefinition('test', 'wordpress', 'project.yml'),
            entities: ['Person' => $person],
        );
    }

    public function testUniqueAndIndexedFieldsProduceTheMatchingIndex(): void
    {
        self::assertArrayHasKey('wp_phe_tag_label_uniq', $this->table('wp_phe_tag')->indexes);
        self::assertTrue($this->table('wp_phe_tag')->indexes['wp_phe_tag_label_uniq']->unique);

        self::assertArrayHasKey('wp_phe_post_title_idx', $this->table('wp_phe_post')->indexes);
        self::assertFalse($this->table('wp_phe_post')->indexes['wp_phe_post_title_idx']->unique);
    }

    public function testTheTablePrefixIsApplied(): void
    {
        $unprefixed = (new SchemaBuilder(new Naming()))->build($this->schema());

        self::assertArrayHasKey('phe_post', $unprefixed);
        self::assertArrayNotHasKey('wp_phe_post', $unprefixed);
    }

    private function table(string $name): TableSchema
    {
        $tables = (new SchemaBuilder(new Naming('wp_')))->build($this->schema());

        self::assertArrayHasKey($name, $tables);

        return $tables[$name];
    }

    private function schema(): Schema
    {
        if (null !== self::$schema) {
            return self::$schema;
        }

        $compiled = (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return self::$schema = $compiled->schema();
    }
}
