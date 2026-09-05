<?php

declare(strict_types=1);

namespace PheFr\Codegen\Output;

/**
 * What a generation run did, or would do.
 */
final readonly class WriteReport
{
    /**
     * @param list<string> $created
     * @param list<string> $updated
     * @param list<string> $unchanged
     * @param list<string> $deleted   Files in the output tree the schema no longer produces.
     * @param list<string> $tampered  Files edited by hand since they were generated.
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $unchanged = [],
        public array $deleted = [],
        public array $tampered = [],
    ) {
    }

    /**
     * Whether the tree on disk already matches what the schema produces.
     */
    public function isClean(): bool
    {
        return [] === $this->created
            && [] === $this->updated
            && [] === $this->deleted
            && [] === $this->tampered;
    }

    public function changeCount(): int
    {
        return count($this->created) + count($this->updated) + count($this->deleted);
    }
}
