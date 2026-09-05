<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

/**
 * What to fetch. Immutable; the with* methods return modified copies.
 */
final readonly class Criteria
{
    /**
     * @param list<Filter> $filters Conjunctive.
     * @param list<Order>  $order
     */
    public function __construct(
        public string $entity,
        public array $filters = [],
        public array $order = [],
        public ?int $limit = null,
        public ?Cursor $after = null,
    ) {
    }

    public function where(Filter $filter): self
    {
        return new self(
            $this->entity,
            [...$this->filters, $filter],
            $this->order,
            $this->limit,
            $this->after,
        );
    }

    public function orderBy(Order $order): self
    {
        return new self(
            $this->entity,
            $this->filters,
            [...$this->order, $order],
            $this->limit,
            $this->after,
        );
    }

    public function take(int $limit, ?Cursor $after = null): self
    {
        return new self($this->entity, $this->filters, $this->order, $limit, $after);
    }
}
