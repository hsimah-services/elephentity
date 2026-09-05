<?php

declare(strict_types=1);

namespace PheFr\WordPress\Integrity;

use PheFr\Schema\Ir\Schema;
use PheFr\WordPress\Database\Database;
use PheFr\WordPress\Sql\Naming;

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
    public function __construct(
        private Schema $schema,
        private Database $database,
        private Naming $naming = new Naming(),
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

        foreach ($this->schema->entities as $entity) {
            if ('wordpress' !== $entity->storage->driver) {
                continue;
            }

            if (null === $entity->field('postId')) {
                continue;
            }

            $tables[] = $this->naming->table($entity);
        }

        return $tables;
    }
}
