<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

final readonly class Index
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
    ) {
    }

    public function definition(): string
    {
        return sprintf(
            '%sKEY `%s` (%s)',
            $this->unique ? 'UNIQUE ' : '',
            $this->name,
            implode(', ', array_map(static fn (string $c): string => sprintf('`%s`', $c), $this->columns)),
        );
    }
}
