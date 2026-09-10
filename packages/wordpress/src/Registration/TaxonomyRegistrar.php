<?php

declare(strict_types=1);

namespace Eleph\WordPress\Registration;

use Eleph\WordPress\Manifest\TaxonomyManifestBuilder;
use RuntimeException;

/**
 * Registers the taxonomies the spec compiled to.
 *
 * As thin as `PostTypeRegistrar`, and for the same reason: every decision was made when
 * the manifest was compiled, so all that is left is the loop that calls WordPress.
 *
 * @see TaxonomyManifestBuilder
 */
final readonly class TaxonomyRegistrar
{
    /**
     * @param array<string, array<string, mixed>> $taxonomies Slug => registration arguments.
     */
    public function __construct(private array $taxonomies)
    {
    }

    /**
     * Load the compiled taxonomies from disk.
     */
    public static function fromManifest(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No taxonomy manifest at %s. Run `eleph generate`, and check the project '
                . 'configures a "wordpress" target.',
                $path,
            ));
        }

        /** @var mixed $taxonomies */
        $taxonomies = require $path;

        if (!is_array($taxonomies)) {
            throw new RuntimeException(sprintf('%s did not return an array of taxonomies.', $path));
        }

        /** @var array<string, array<string, mixed>> $taxonomies */
        return new self($taxonomies);
    }

    /**
     * Hook this on `init`, same as `PostTypeRegistrar` — and before it, if a post type
     * this taxonomy applies to is registered in the same request, since WordPress
     * accepts registering a taxonomy for a not-yet-registered post type but some
     * ecosystem code assumes the reverse.
     */
    public function register(): void
    {
        foreach ($this->taxonomies as $slug => $arguments) {
            $objectType = $arguments['object_type'] ?? [];
            unset($arguments['object_type']);

            // The schema compiler has already rejected any handle that is not a legal
            // taxonomy slug, and the argument shape is derived, not user-supplied.
            // @phpstan-ignore argument.type, argument.type
            register_taxonomy($slug, $objectType, $arguments);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function arguments(): array
    {
        return $this->taxonomies;
    }
}
