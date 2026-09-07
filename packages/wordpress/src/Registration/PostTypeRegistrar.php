<?php

declare(strict_types=1);

namespace Eleph\WordPress\Registration;

use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Schema;

/**
 * Registers a WordPress post type for entities that declare a handle.
 *
 * Everything variable comes from pattern configuration, which the entity supplies and
 * the compiler validated — so this reads keys like `visibility` and `supports` without
 * the core ever learning what they mean.
 *
 * Registering the type is all this does. **Nothing here creates or maintains a post
 * row** — the custom table is the entity, and a `postId` field is a column like any
 * other, filled by whatever the application decides fills it (a `postCommit` trigger
 * calling `wp_insert_post()` is the obvious shape).
 *
 * Said plainly because the opposite was once written here: where both records exist
 * they can diverge, the custom table is authoritative, and keeping the projection in
 * step is the application's job. Delete events are dispatched for cascades too, so
 * that job is at least possible to do.
 */
final readonly class PostTypeRegistrar
{
    /**
     * Nothing by default.
     *
     * A post row, where one exists, is there so the ecosystem has something to hold on
     * to, not to be edited: a post editor that can change the title would be editing a
     * copy of data the custom table owns, and nothing would notice. An entity that
     * genuinely wants the admin to edit something says so with `supports:`.
     */
    private const NO_SUPPORTS = [];

    public function __construct(private Schema $schema)
    {
    }

    /**
     * Hook this on `init`.
     */
    public function register(): void
    {
        foreach ($this->arguments() as $handle => $arguments) {
            // The schema compiler has already rejected any handle that is not a legal
            // post type slug — lowercase, non-empty, within 20 characters — and the
            // argument shape is derived, not user-supplied.
            // @phpstan-ignore argument.type, argument.type
            register_post_type($handle, $arguments);
        }
    }

    /**
     * The registration arguments, derived from the spec.
     *
     * Separated from the call so the derivation can be tested without WordPress.
     *
     * @return array<string, array<string, mixed>>
     */
    public function arguments(): array
    {
        $types = [];

        foreach ($this->schema->entities as $entity) {
            $handle = $entity->storage->handle;

            if ('wordpress' !== $entity->storage->driver || null === $handle) {
                continue;
            }

            $public = 'public' === $entity->configured('visibility', 'private');
            $adminMenu = $entity->configured('adminMenu');

            $types[$handle] = [
                'labels' => $this->labels($entity),
                'description' => $entity->description ?? '',
                'public' => $public,
                'publicly_queryable' => $public,
                'exclude_from_search' => !$public,
                'show_ui' => true === $entity->configured('showInAdmin', true),
                'show_in_menu' => is_string($adminMenu) ? $adminMenu : true === $entity->configured('showInAdmin', true),
                'show_in_rest' => true === $entity->configured('showInRest', false),
                'capability_type' => $entity->configured('capabilityType', 'post'),
                'supports' => $this->supports($entity),
            ];
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private function supports(EntityDefinition $entity): array
    {
        $supports = $entity->configured('supports', self::NO_SUPPORTS);

        if (!is_array($supports)) {
            return self::NO_SUPPORTS;
        }

        return array_values(array_filter($supports, is_string(...)));
    }

    /**
     * WordPress wants nine labels and writing them out is exactly the boilerplate this
     * framework exists to delete, so they are derived from the entity name.
     *
     * The plural is the one place the generator does pluralise, because WordPress
     * genuinely needs one — hence the `pluralLabel` escape hatch for the words English
     * inflection gets wrong.
     *
     * @return array<string, string>
     */
    private function labels(EntityDefinition $entity): array
    {
        $singular = $entity->configured('label');
        $singular = is_string($singular) ? $singular : $this->humanise($entity->name);

        $plural = $entity->configured('pluralLabel');
        $plural = is_string($plural) ? $plural : $this->pluralise($singular);

        $lower = strtolower($plural);

        return [
            'name' => $plural,
            'singular_name' => $singular,
            'add_new_item' => sprintf('Add New %s', $singular),
            'edit_item' => sprintf('Edit %s', $singular),
            'new_item' => sprintf('New %s', $singular),
            'view_item' => sprintf('View %s', $singular),
            'search_items' => sprintf('Search %s', $plural),
            'not_found' => sprintf('No %s found', $lower),
            'not_found_in_trash' => sprintf('No %s found in Trash', $lower),
        ];
    }

    /**
     * PascalCase to words: ProductVariation becomes "Product Variation".
     */
    private function humanise(string $name): string
    {
        return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $name));
    }

    /**
     * Deliberately naive, and deliberately overridable.
     *
     * Anything beyond the two commonest English rules is guesswork, and guessing at a
     * label a user will read is worse than asking for it.
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
