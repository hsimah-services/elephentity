<?php

declare(strict_types=1);

namespace PheFr\WordPress\Registration;

use PheFr\Schema\Ir\Schema;

/**
 * Registers a WordPress post type for entities that declare a handle.
 *
 * Only entities that genuinely need the ecosystem get one. Where a post type exists,
 * the custom table row and the post row are two records that can diverge, so the
 * custom table is authoritative and the post row is a projection the mutator writes as
 * part of the same unit of work.
 */
final readonly class PostTypeRegistrar
{
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

            $types[$handle] = [
                'label' => $entity->name,
                'description' => $entity->description ?? '',
                'public' => true,
                'show_in_rest' => false,
                // Content lives in the custom table; the post row exists so that the
                // ecosystem has something to hold on to.
                'supports' => ['title'],
            ];
        }

        return $types;
    }
}
