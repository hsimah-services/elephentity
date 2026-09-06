<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Resolver;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Query\CachingEdgeLoader;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Storage\Cursor;
use Eleph\WPGraphQL\Manifest\ConnectionEntry;

/**
 * Resolves a connection from the lazy query an edge accessor returns.
 *
 * This is where batching finally becomes real. Everywhere else in the framework, a
 * caller asks one entity for one edge and the loader has no way to know that
 * forty-nine more identical questions are about to follow. A connection resolver does
 * know: GraphQL hands it the whole parent set at once, so it can warm the loader with
 * a single query before any child is touched.
 *
 * That is why preload() is called here explicitly rather than being hidden inside the
 * loader. Batching that guesses is batching that surprises.
 */
final readonly class ConnectionResolver
{
    private const DEFAULT_PAGE_SIZE = 10;

    public function __construct(private CachingEdgeLoader $loader)
    {
    }

    /**
     * Warm the loader for every parent GraphQL is about to resolve.
     *
     * @param list<EntityId> $parents
     */
    public function preload(ConnectionEntry $connection, array $parents): void
    {
        $this->loader->preload($connection->fromType, $parents, $connection->edge);
    }

    /**
     * @param EntityQuery<object>  $query
     * @param array<string, mixed> $args
     *
     * @return array{nodes: list<object>, pageInfo: array{hasNextPage: bool, endCursor: string|null}, totalCount: int}
     */
    public function resolve(EntityQuery $query, array $args): array
    {
        $first = $args['first'] ?? self::DEFAULT_PAGE_SIZE;
        $after = $args['after'] ?? null;

        $page = $query->page(
            is_int($first) ? $first : self::DEFAULT_PAGE_SIZE,
            is_string($after) && '' !== $after ? Cursor::of($after) : null,
        );

        return [
            'nodes' => $page->items,
            'pageInfo' => [
                'hasNextPage' => $page->hasMore(),
                'endCursor' => null === $page->next ? null : (string) $page->next,
            ],
            // Counted rather than derived from the page, and never by hydrating: the
            // adaptor counts, so totalCount on a million-row edge costs one query.
            'totalCount' => $query->count(),
        ];
    }
}
