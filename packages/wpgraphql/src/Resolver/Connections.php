<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Resolver;

use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Offset;

/**
 * Shapes a lazy query into what a GraphQL connection expects.
 *
 * Nothing is hydrated that the caller did not ask for: `page()` fetches one page and
 * `count()` asks the adaptor to count, so a table of twenty rows out of a million
 * costs two queries rather than a million objects.
 */
final readonly class Connections
{
    public const DEFAULT_PAGE_SIZE = 10;

    private const MAX_PAGE_SIZE = 100;

    /**
     * @param EntityQuery<object>  $query
     * @param array<array-key, mixed> $args
     *
     * @return array{
     *     nodes: list<object>,
     *     edges: list<array{cursor: string, node: object}>,
     *     pageInfo: array{hasNextPage: bool, hasPreviousPage: bool, startCursor: string|null, endCursor: string|null},
     *     totalCount: int
     * }
     */
    public function resolve(EntityQuery $query, array $args): array
    {
        $after = is_string($args['after'] ?? null) && '' !== $args['after']
            ? Cursor::of($args['after'])
            : null;

        $page = $query->page($this->size($args), $after);

        $start = Offset::fromCursor($after)->value;
        $edges = [];

        foreach ($page->items as $index => $node) {
            $edges[] = [
                // Positional, matching the offset cursors the adaptor pages with. A
                // node's cursor is where it sits, not what it is.
                'cursor' => (string) (new Offset($start + $index + 1))->toCursor(),
                'node' => $node,
            ];
        }

        return [
            'nodes' => $page->items,
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $page->hasMore(),
                'hasPreviousPage' => $start > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => [] === $edges ? null : $edges[count($edges) - 1]['cursor'],
            ],
            'totalCount' => $query->count(),
        ];
    }

    /**
     * @param array<array-key, mixed> $args
     */
    private function size(array $args): int
    {
        $first = $args['first'] ?? null;

        if (!is_int($first)) {
            return self::DEFAULT_PAGE_SIZE;
        }

        // Capped rather than trusted: `first: 1000000` is how a connection stops being
        // a connection.
        return min(max($first, 1), self::MAX_PAGE_SIZE);
    }
}
