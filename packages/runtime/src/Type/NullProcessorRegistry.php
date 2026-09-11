<?php

declare(strict_types=1);

namespace Eleph\Runtime\Type;

use RuntimeException;

/**
 * The registry a project with no `processors: true` types needs.
 *
 * `UnitOfWorkFactory` takes a `ProcessorRegistry` unconditionally, because it asks
 * about every declared type without knowing in advance that a given project declares
 * none. Without this, a project with zero such types still has to write an
 * implementation before it can construct one — and the obvious wrong one, returning
 * the value it was given, would silently skip the verification a declared type exists
 * to perform. `has()` always answering false is what keeps that mistake from
 * happening: nothing that calls `read()` or `write()` does so without checking `has()`
 * first, so those two only run when a project misuses the registry directly.
 */
final readonly class NullProcessorRegistry implements ProcessorRegistry
{
    public function has(string $type): bool
    {
        return false;
    }

    public function read(string $type): ReadProcessor
    {
        throw new RuntimeException(sprintf('%s declares no read processor.', $type));
    }

    public function write(string $type): WriteProcessor
    {
        throw new RuntimeException(sprintf('%s declares no write processor.', $type));
    }
}
