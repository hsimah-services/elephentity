<?php

declare(strict_types=1);

namespace PheFr\WordPress\Tests;

use PheFr\Schema\Ir\Schema;
use PheFr\Schema\SchemaCompiler;
use PheFr\Schema\SpecSource;
use PheFr\WordPress\Integrity\OrphanGuard;
use PheFr\WordPress\Registration\PostTypeRegistrar;
use PheFr\WordPress\Sql\Naming;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The two places PheFr has to meet WordPress on its own terms.
 */
#[CoversClass(PostTypeRegistrar::class)]
#[CoversClass(OrphanGuard::class)]
final class WordPressIntegrationTest extends TestCase
{
    private static ?Schema $schema = null;

    public function testOnlyEntitiesDeclaringAHandleGetAPostType(): void
    {
        // A post type exists where the WP ecosystem needs one, not for every entity.
        $types = (new PostTypeRegistrar($this->schema()))->arguments();

        self::assertSame(['post'], array_keys($types));
        self::assertSame('Post', $types['post']['label']);
        self::assertSame('A published article.', $types['post']['description']);
    }

    public function testThePostRowIsAProjectionRatherThanTheContent(): void
    {
        // The custom table is authoritative; the post row exists so the ecosystem has
        // something to hold on to, so it supports almost nothing itself.
        $types = (new PostTypeRegistrar($this->schema()))->arguments();

        self::assertSame(['title'], $types['post']['supports']);
        self::assertFalse($types['post']['show_in_rest']);
    }

    public function testTheOrphanGuardWatchesOnlyTablesThatTrackPosts(): void
    {
        $guard = new OrphanGuard($this->schema(), new FakeDatabase(), new Naming('wp_'));

        // Only Post carries the WordPressPost pattern's postId.
        self::assertSame(['wp_phe_post'], $guard->tablesTrackingPosts());
    }

    public function testDeletingAPostCleansUpTheRowPointingAtIt(): void
    {
        // Nothing in the framework sees someone empty the trash in wp-admin, so
        // without this the post row goes and the custom row survives, pointing at
        // nothing.
        $database = new FakeDatabase();

        (new OrphanGuard($this->schema(), $database, new Naming('wp_')))->onPostDeleted(99);

        self::assertCount(1, $database->statements);
        self::assertSame(
            'DELETE FROM `wp_phe_post` WHERE `post_id` = %d',
            $database->statements[0]['sql'],
        );
        self::assertSame([99], $database->statements[0]['bindings']);
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
