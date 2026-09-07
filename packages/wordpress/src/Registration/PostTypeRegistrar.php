<?php

declare(strict_types=1);

namespace Eleph\WordPress\Registration;

use Eleph\WordPress\Manifest\PostTypeManifestBuilder;
use RuntimeException;

/**
 * Registers the post types the spec compiled to.
 *
 * As thin as the GraphQL registrar, and for the same reason: every decision was made
 * when the manifest was compiled, so all that is left is the loop that calls WordPress.
 * It used to take the compiled `Schema` and derive the arguments per request, which
 * meant a plugin registering post types shipped the spec compiler and parsed YAML on
 * every request. The derivation now lives in `PostTypeManifestBuilder`, at build time.
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
 *
 * @see PostTypeManifestBuilder
 */
final readonly class PostTypeRegistrar
{
    /**
     * @param array<string, array<string, mixed>> $types Post type slug => registration arguments.
     */
    public function __construct(private array $types)
    {
    }

    /**
     * Load the compiled post types from disk.
     */
    public static function fromManifest(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No post type manifest at %s. Run `eleph generate`, and check the project '
                . 'configures a "wordpress" target.',
                $path,
            ));
        }

        /** @var mixed $types */
        $types = require $path;

        if (!is_array($types)) {
            throw new RuntimeException(sprintf('%s did not return an array of post types.', $path));
        }

        /** @var array<string, array<string, mixed>> $types */
        return new self($types);
    }

    /**
     * Hook this on `init`.
     */
    public function register(): void
    {
        foreach ($this->types as $handle => $arguments) {
            // The schema compiler has already rejected any handle that is not a legal
            // post type slug — lowercase, non-empty, within 20 characters — and the
            // argument shape is derived, not user-supplied.
            // @phpstan-ignore argument.type, argument.type
            register_post_type($handle, $arguments);
        }
    }

    /**
     * The registration arguments, for anything that wants to inspect them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function arguments(): array
    {
        return $this->types;
    }
}
