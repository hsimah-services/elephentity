<?php

declare(strict_types=1);

namespace Eleph\WordPress;

use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Manifest\StorageManifest;
use Eleph\WordPress\Sql\FieldMap;
use Eleph\WordPress\Sql\QueryCompiler;
use RuntimeException;

/**
 * Builds the adaptor from the compiled manifest.
 *
 * The last assembly step on the storage side, and the only one that needs the
 * installation rather than the spec: the table prefix comes from `$wpdb`, because a
 * WordPress install can use any and multisite uses a different one per site.
 */
final readonly class WordPress
{
    public static function adaptor(Database $database, StorageManifest $manifest): WordPressAdaptor
    {
        $prefixed = $manifest->withPrefix($database->prefix());

        return new WordPressAdaptor(
            $database,
            $prefixed->tables,
            new FieldMap($prefixed->columns),
            $prefixed->placements,
            new QueryCompiler(
                placements: $prefixed->placements,
                taxonomyPlacements: $prefixed->taxonomyPlacements,
                // WordPress's own tables, not this schema's: wp_term_relationships and
                // wp_term_taxonomy carry only $wpdb's own prefix, never the additional
                // layer withPrefix() just applied to this schema's own tables.
                termRelationshipsTable: $database->prefix() . 'term_relationships',
                termTaxonomyTable: $database->prefix() . 'term_taxonomy',
            ),
            $prefixed->taxonomies,
            $prefixed->taxonomyPlacements,
        );
    }

    /**
     * Load the compiled manifest from disk.
     */
    public static function manifest(string $path): StorageManifest
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No storage manifest at %s. Run `eleph generate`.',
                $path,
            ));
        }

        /** @var mixed $manifest */
        $manifest = require $path;

        if (!$manifest instanceof StorageManifest) {
            throw new RuntimeException(sprintf('%s did not return a StorageManifest.', $path));
        }

        return $manifest;
    }
}
