<?php

declare(strict_types=1);

namespace Clog;

use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\Type\ReadProcessor;
use Eleph\Runtime\Type\WriteProcessor;
use RuntimeException;

/**
 * The registry a project with no `processors: true` types needs.
 *
 * Clog declares one type, `ExpiryUnit`, and it is an enum — the generator owns those
 * outright, so nothing here has a processor. The registry is still required, because
 * the unit of work asks it about every declared type without knowing in advance that
 * the answer is always no.
 *
 * A project with a `Money` binds `MoneyReadProcessor` and `MoneyWriteProcessor` in the
 * container and returns them from here.
 */
final readonly class NoProcessors implements ProcessorRegistry
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
