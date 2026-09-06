<?php

declare(strict_types=1);

namespace Eleph\Runtime\Type;

use Eleph\Runtime\Boot\MissingImplementations;

/**
 * Resolves the processors a declared type needs.
 *
 * Types are named in the spec and processors are supplied by the application, so the
 * two meet here. A type declaring `processors: true` with nothing implementing them
 * is a boot failure, not a runtime surprise.
 *
 * @see MissingImplementations
 */
interface ProcessorRegistry
{
    public function has(string $type): bool;

    /**
     * @return ReadProcessor<scalar, mixed>
     */
    public function read(string $type): ReadProcessor;

    /**
     * @return WriteProcessor<scalar, mixed>
     */
    public function write(string $type): WriteProcessor;
}
