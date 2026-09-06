<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

/**
 * A parsed but not yet validated spec document, with the path it came from.
 */
final readonly class RawSpec
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public SpecKind $kind,
        public string $file,
        public array $data,
    ) {
    }

    public function name(): string
    {
        $name = $this->data[$this->kind->value] ?? null;

        return is_string($name) ? $name : '';
    }

    public function reader(): SpecReader
    {
        return new SpecReader($this->data);
    }
}
