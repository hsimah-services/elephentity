<?php

declare(strict_types=1);

namespace PheFr\WordPress\Sql;

/**
 * A SQL statement and the values to bind into it.
 *
 * Placeholders are wpdb's %s/%d/%f style, so the statement is prepared by wpdb rather
 * than interpolated here.
 */
final readonly class CompiledQuery
{
    /**
     * @param list<scalar|null> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
    ) {
    }
}
