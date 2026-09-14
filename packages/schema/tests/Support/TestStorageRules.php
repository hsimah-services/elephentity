<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests\Support;

use Eleph\Schema\Storage\StorageRuleRegistry;
use Eleph\Schema\Storage\StorageRules;

/**
 * The storage rules the shared `semantic` fixture needs to compile.
 *
 * A real driver — `elephentity/wordpress` — declares these over the wire at describe
 * time; `packages/schema` cannot depend on it without inverting the layering, so this
 * restates the shape the fixture relies on. See `TestIntegrations` for the same
 * reasoning.
 */
final readonly class TestStorageRules
{
    public static function registry(): StorageRuleRegistry
    {
        return new StorageRuleRegistry([
            'wordpress' => new StorageRules(
                maxHandleLength: 20,
                handlePattern: '/^[a-z][a-z0-9_-]*$/',
            ),
        ]);
    }
}
