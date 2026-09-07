<?php

declare(strict_types=1);

namespace Eleph\WordPress\Integrity;

use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Manifest\StorageManifest;

/**
 * Keeps custom tables in step when WordPress deletes a post behind our back.
 *
 * No amount of framework-level enforcement sees someone empty the trash in wp-admin,
 * or another plugin call wp_delete_post(). Without this hook the post row disappears
 * and the custom table row survives, pointing at nothing.
 *
 * The custom table is authoritative, so this is a projection being cleaned up rather
 * than a cascade: it removes the row whose post_id no longer resolves, and does not
 * attempt to run actions or triggers, which cannot meaningfully fire for a deletion
 * the framework never saw.
 */
final readonly class OrphanGuard
{
    /**
     * The compiled manifest rather than the spec, so this works at run time.
     *
     * It carries a field-to-column map per entity, which already answers the only
     * question here — which entities project to a post row — so nothing new had to be
     * compiled for it. Taking the `Schema` meant shipping the spec compiler to
     * production and parsing YAML to answer it.
     */
    public function __construct(
        private StorageManifest $manifest,
        private Database $database,
    ) {
    }

    /**
     * Hook this on `before_delete_post`.
     */
    public function onPostDeleted(int $postId): void
    {
        foreach ($this->tablesTrackingPosts() as $table) {
            $this->database->execute(
                sprintf('DELETE FROM `%s` WHERE `post_id` = %%d', $table),
                [$postId],
            );
        }
    }

    /**
     * Tables whose entity carries the WordPressPost pattern's post_id.
     *
     * @return list<string>
     */
    public function tablesTrackingPosts(): array
    {
        $tables = [];

        foreach ($this->manifest->columns as $entity => $columns) {
            if (!isset($columns['postId'], $this->manifest->tables[$entity])) {
                continue;
            }

            $tables[] = $this->manifest->tables[$entity]->name;
        }

        sort($tables);

        return $tables;
    }
}
