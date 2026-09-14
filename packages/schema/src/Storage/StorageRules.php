<?php

declare(strict_types=1);

namespace Eleph\Schema\Storage;

/**
 * What a driver requires of `storage.handle`, declared by the driver itself.
 *
 * Every field is optional: a driver that imposes no rule on handles — or no notion of
 * a handle at all — simply declares nothing, and an entity on that driver validates
 * without handle errors. Nothing here is WordPress-specific; the 20-character limit
 * and the lowercase-slug pattern are that driver's own declaration, not a compiler
 * default.
 */
final readonly class StorageRules
{
    /**
     * @param list<string> $reservedHandles Handles no entity may take, spelled by the driver.
     */
    public function __construct(
        public ?int $maxHandleLength = null,
        public ?string $handlePattern = null,
        public array $reservedHandles = [],
    ) {
    }
}
