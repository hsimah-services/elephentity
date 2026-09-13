<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Runtime\Gateway\EntityGateway;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Policy\AccessDenied;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Page;

/**
 * Records what the GraphQL layer asked the runtime for.
 *
 * The layer's job is dispatch: given a field, call the right thing with the right
 * arguments. What the runtime then does is somebody else's test.
 */
final class FakeGateway implements EntityGateway
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<object> */
    public array $items = [];

    /** @var array<string, mixed> The input the last write was handed. */
    public array $input = [];

    public int $total = 0;

    public bool $hasMore = false;

    public bool $denyReads = false;

    public function find(string $entity, EntityId $id): ?object
    {
        $this->calls[] = sprintf('find %s#%s', $entity, $id);

        if ($this->denyReads) {
            throw new AccessDenied($entity, 'owner', 'Not allowed.');
        }

        return $this->items[0] ?? null;
    }

    public function all(string $entity): EntityQuery
    {
        $this->calls[] = sprintf('all %s', $entity);

        return $this->query();
    }

    public function runQuery(string $entity, string $query, array $args): EntityQuery
    {
        $this->calls[] = sprintf('query %s::%s(%s)', $entity, $query, implode(',', array_keys($args)));

        return $this->query();
    }

    public function create(string $entity, array $input): EntityId
    {
        $this->calls[] = sprintf('create %s(%s)', $entity, implode(',', array_keys($input)));
        $this->input = $input;

        return EntityId::of(1);
    }

    public function update(string $entity, EntityId $id, array $input): void
    {
        $this->calls[] = sprintf('update %s#%s(%s)', $entity, $id, implode(',', array_keys($input)));
        $this->input = $input;
    }

    public function delete(string $entity, EntityId $id): void
    {
        $this->calls[] = sprintf('delete %s#%s', $entity, $id);
    }

    public function runAction(string $entity, string $action, EntityId $id, array $args): void
    {
        $this->calls[] = sprintf('action %s::%s#%s(%s)', $entity, $action, $id, implode(',', array_keys($args)));
    }

    /**
     * @return EntityQuery<object>
     */
    private function query(): EntityQuery
    {
        return new class ($this->items, $this->total, $this->hasMore) implements EntityQuery {
            /** @param list<object> $items */
            public function __construct(
                private readonly array $items,
                private readonly int $total,
                private readonly bool $hasMore,
            ) {
            }

            public function count(): int
            {
                return $this->total;
            }

            public function page(int $limit, ?Cursor $after = null): Page
            {
                return new Page(
                    array_slice($this->items, 0, $limit),
                    $this->hasMore ? Cursor::of('next') : null,
                );
            }

            public function all(): array
            {
                return $this->items;
            }

            public function first(): ?object
            {
                return $this->items[0] ?? null;
            }

            public function exists(): bool
            {
                return [] !== $this->items;
            }
        };
    }
}
