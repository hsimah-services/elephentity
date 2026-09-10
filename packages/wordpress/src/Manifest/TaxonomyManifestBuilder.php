<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\WordPress\Sql\EdgePlanner;

/**
 * Compiles the arguments `register_taxonomy()` will be called with.
 *
 * `object_type` is never declared: it is derived from whichever entities have a
 * many-to-many edge pointing at this one, the same way a post type's own fields are
 * derived from `fields:` rather than named twice. An entity nothing edges to still
 * gets a taxonomy — just one nothing classifies yet.
 */
final readonly class TaxonomyManifestBuilder
{
    /**
     * @return array<string, array<string, mixed>> Taxonomy slug => registration arguments.
     */
    public function build(Schema $schema): array
    {
        $objectTypes = $this->objectTypes($schema);
        $taxonomies = [];

        foreach ($schema->entities as $entity) {
            if (!EdgePlanner::isTaxonomy($entity)) {
                continue;
            }

            $slug = (string) $entity->storage->handle;
            $public = true === $entity->configured('public', true);
            $hierarchical = true === $entity->configured('hierarchical', false);
            $showInAdmin = true === $entity->configured('showInAdmin', true);

            $taxonomies[$slug] = [
                'labels' => $this->labels($entity),
                'description' => $entity->description ?? '',
                'public' => $public,
                'publicly_queryable' => $public,
                'hierarchical' => $hierarchical,
                'show_ui' => $showInAdmin,
                'show_admin_column' => $showInAdmin,
                'show_in_rest' => true === $entity->configured('showInRest', false),
                'object_type' => $objectTypes[$entity->name] ?? [],
            ];
        }

        ksort($taxonomies);

        return $taxonomies;
    }

    /**
     * @return array<string, list<string>> Taxonomy entity name => post type slugs that edge to it.
     */
    private function objectTypes(Schema $schema): array
    {
        $types = [];

        foreach ($schema->entities as $entity) {
            $handle = 'wordpress' === $entity->storage->driver && !EdgePlanner::isTaxonomy($entity)
                ? $entity->storage->handle
                : null;

            foreach ($entity->edges as $edge) {
                $target = $schema->entity($edge->to);

                if (null === $target || !EdgePlanner::isTaxonomy($target)) {
                    continue;
                }

                if (null !== $handle) {
                    $types[$target->name][] = $handle;
                } elseif (!isset($types[$target->name])) {
                    $types[$target->name] = [];
                }
            }
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function labels(EntityDefinition $entity): array
    {
        $singular = $entity->configured('label');
        $singular = is_string($singular) ? $singular : $this->humanise($entity->name);

        $plural = $entity->configured('pluralLabel');
        $plural = is_string($plural) ? $plural : $this->pluralise($singular);

        return [
            'name' => $plural,
            'singular_name' => $singular,
            'search_items' => sprintf('Search %s', $plural),
            'all_items' => sprintf('All %s', $plural),
            'edit_item' => sprintf('Edit %s', $singular),
            'add_new_item' => sprintf('Add New %s', $singular),
            'new_item_name' => sprintf('New %s Name', $singular),
        ];
    }

    /**
     * PascalCase to words: PartnerType becomes "Partner Type".
     */
    private function humanise(string $name): string
    {
        return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name));
    }

    /**
     * Deliberately naive, and deliberately overridable — see `PostTypeManifestBuilder`.
     */
    private function pluralise(string $singular): string
    {
        if (1 === preg_match('/(s|x|z|ch|sh)$/i', $singular)) {
            return $singular . 'es';
        }

        if (1 === preg_match('/[^aeiou]y$/i', $singular)) {
            return substr($singular, 0, -1) . 'ies';
        }

        return $singular . 's';
    }
}
