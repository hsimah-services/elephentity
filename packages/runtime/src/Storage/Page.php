<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

/**
 * One page of results, with the cursor needed to ask for the next.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public ?Cursor $next = null,
    ) {
    }

    /**
     * @return self<T>
     */
    public static function empty(): self
    {
        return new self([]);
    }

    public function hasMore(): bool
    {
        return null !== $this->next;
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
