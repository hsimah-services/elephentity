<?php

declare(strict_types=1);

namespace Eleph\Schema\Wire;

use Eleph\Schema\Storage\StorageRules;

/**
 * A driver's storage rules, over the wire.
 *
 * `provides.storage` is off the contract surface the same way `provides.patterns` and
 * `provides.integrations` are, so this is a small, explicit shape rather than anything
 * reflected — see `IntegrationCodec` for the same reasoning.
 */
final readonly class StorageRulesCodec
{
    /**
     * @param array<string, mixed> $data
     */
    public static function decode(string $target, array $data): StorageRules
    {
        $handle = $data['handle'] ?? [];

        if (!is_array($handle)) {
            throw new WireException(sprintf('The %s builder declared "storage.handle" as something other than an object.', $target));
        }

        $maxLength = $handle['maxLength'] ?? null;

        if (null !== $maxLength && !is_int($maxLength)) {
            throw new WireException(sprintf('The %s builder declared a non-integer "storage.handle.maxLength".', $target));
        }

        $pattern = $handle['pattern'] ?? null;

        if (null !== $pattern && !is_string($pattern)) {
            throw new WireException(sprintf('The %s builder declared a non-string "storage.handle.pattern".', $target));
        }

        $reserved = $handle['reserved'] ?? [];

        if (!is_array($reserved)) {
            throw new WireException(sprintf('The %s builder declared "storage.handle.reserved" as something other than a list.', $target));
        }

        return new StorageRules(
            maxHandleLength: $maxLength,
            handlePattern: $pattern,
            reservedHandles: array_values(array_filter($reserved, is_string(...))),
        );
    }
}
