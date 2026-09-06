<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * What is true of the whole project.
 *
 * Deliberately shallow. It carries settings no entity can sensibly vary — chiefly the
 * driver, since one unit of work has one adaptor — and nothing else.
 *
 * In particular it may not declare patterns or fields applied to every entity. That is
 * the obvious next request and it would mean reading an entity spec no longer tells
 * you what that entity has, which is exactly why patterns are sealed.
 */
final readonly class ProjectDefinition
{
    public function __construct(
        public string $name,
        public string $driver,
        public string $sourceFile,
        /**
         * Prepended to every entity's table.
         *
         * Stacks with whatever prefix the driver adds of its own: under WordPress the
         * real table is $wpdb->prefix, then this, then the entity's own table name.
         */
        public string $tablePrefix = '',
        public ?string $description = null,
    ) {
    }
}
