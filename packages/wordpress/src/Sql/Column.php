<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

final readonly class Column
{
    public function __construct(
        public string $name,
        /** The SQL type, e.g. VARCHAR(255) or BIGINT UNSIGNED. */
        public string $type,
        public bool $nullable = false,
        public bool $autoIncrement = false,
        public ?string $default = null,
    ) {
    }

    public function definition(): string
    {
        $sql = sprintf('`%s` %s', $this->name, $this->type);
        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        if (null !== $this->default) {
            $sql .= ' DEFAULT ' . $this->default;
        }

        if ($this->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }
}
