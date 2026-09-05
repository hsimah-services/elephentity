<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage\Write;

use Countable;

/**
 * An ordered set of writes to apply together.
 *
 * Order is significant and is the unit of work's responsibility, not the adaptor's:
 * with server-generated ids a Post must be inserted before the Comments that reference
 * it, so the batch arrives already sorted by dependency.
 */
final readonly class WriteBatch implements Countable
{
    /** @var list<WriteOperation> */
    public array $operations;

    public function __construct(WriteOperation ...$operations)
    {
        $this->operations = array_values($operations);
    }

    public function isEmpty(): bool
    {
        return [] === $this->operations;
    }

    public function count(): int
    {
        return count($this->operations);
    }
}
