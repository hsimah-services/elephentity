<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\WordPress\Integrity\OrphanGuard;
use Eleph\WordPress\Manifest\StorageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Keeping custom tables in step when WordPress deletes a post behind our back — one of
 * the two places Elephentity has to meet WordPress on its own terms, the other being
 * post type registration itself, which is now `elephentity-codegen-wordpress`'s own
 * concern (elephentity#62) and tested there.
 */
#[CoversClass(OrphanGuard::class)]
final class OrphanGuardTest extends TestCase
{
    private static ?StorageManifest $manifest = null;

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
     * Prefixed the way `$wpdb` prefixes it at boot, since that is where the guard's
     * table names have to come from. Loaded from a frozen fixture rather than compiled
     * here: the builder that produces a manifest (elephentity-codegen-wordpress) is a
     * separate repository now.
     */
    private function manifest(): StorageManifest
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }

        /** @var mixed $manifest */
        $manifest = require __DIR__ . '/fixtures/storage-manifest.php';

        if (!$manifest instanceof StorageManifest) {
            throw new RuntimeException('fixtures/storage-manifest.php did not return a StorageManifest.');
        }

        return self::$manifest = $manifest->withPrefix('wp_');
    }
}
