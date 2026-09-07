<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\WordPress\Integrity\OrphanGuard;
use Eleph\WordPress\Manifest\PostTypeManifestBuilder;
use Eleph\WordPress\Manifest\StorageManifest;
use Eleph\WordPress\Manifest\StorageManifestBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two places Elephentity has to meet WordPress on its own terms.
 */
#[CoversClass(PostTypeManifestBuilder::class)]
#[CoversClass(OrphanGuard::class)]
final class WordPressIntegrationTest extends TestCase
{
    private static ?Schema $schema = null;

    public function testOnlyEntitiesDeclaringAHandleGetAPostType(): void
    {
        // A post type exists where the WP ecosystem needs one, not for every entity.
        $types = $this->postTypes();

        self::assertSame(['post'], array_keys($types));
        self::assertSame('A published article.', $types['post']['description']);
    }

    public function testRegistrationArgumentsComeFromPatternConfiguration(): void
    {
        // The fixture's WordPressPost pattern declares visibility and supports; Post
        // configures them. Nothing here is hardcoded, and packages/schema validated
        // the values without knowing what any of them mean.
        $types = $this->postTypes();

        self::assertTrue($types['post']['public']);
        self::assertTrue($types['post']['publicly_queryable']);
        self::assertFalse($types['post']['exclude_from_search']);
        self::assertSame(['title', 'editor'], $types['post']['supports']);
    }

    public function testLabelsAreDerivedFromTheEntityName(): void
    {
        // Nine labels is exactly the boilerplate this framework exists to delete.
        $labels = $this->postTypes()['post']['labels'];

        self::assertIsArray($labels);

        self::assertSame('Post', $labels['singular_name']);
        self::assertSame('Posts', $labels['name']);
        self::assertSame('Add New Post', $labels['add_new_item']);
        self::assertSame('No posts found in Trash', $labels['not_found_in_trash']);
    }

    public function testTheOrphanGuardWatchesOnlyTablesThatTrackPosts(): void
    {
        $guard = new OrphanGuard($this->manifest(), new FakeDatabase());

        // Only Post carries the WordPressPost pattern's postId.
        self::assertSame(['wp_phe_post'], $guard->tablesTrackingPosts());
    }

    public function testDeletingAPostCleansUpTheRowPointingAtIt(): void
    {
        // Nothing in the framework sees someone empty the trash in wp-admin, so
        // without this the post row goes and the custom row survives, pointing at
        // nothing.
        $database = new FakeDatabase();

        (new OrphanGuard($this->manifest(), $database))->onPostDeleted(99);

        self::assertCount(1, $database->statements);
        self::assertSame(
            'DELETE FROM `wp_phe_post` WHERE `post_id` = %d',
            $database->statements[0]['sql'],
        );
        self::assertSame([99], $database->statements[0]['bindings']);
    }

    /**
     * The compiled post types, the way a plugin gets them: through the manifest.
     *
     * @return array<string, array<string, mixed>>
     */
    private function postTypes(): array
    {
        return (new PostTypeManifestBuilder())->build($this->schema());
    }

    /**
     * Prefixed the way `$wpdb` prefixes it at boot, since that is where the guard's
     * table names have to come from.
     */
    private function manifest(): StorageManifest
    {
        return (new StorageManifestBuilder())->build($this->schema())->withPrefix('wp_');
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
