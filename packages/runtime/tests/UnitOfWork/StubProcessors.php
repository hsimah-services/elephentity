<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\Type\ReadProcessor;
use Eleph\Runtime\Type\WriteProcessor;
use RuntimeException;

final class StubProcessors implements ProcessorRegistry
{
    /**
     * @param array<string, WriteProcessor<scalar, mixed>> $writers
     */
    public function __construct(private readonly array $writers = [])
    {
    }

    public function has(string $type): bool
    {
        return isset($this->writers[$type]);
    }

    public function read(string $type): ReadProcessor
    {
        throw new RuntimeException('not needed');
    }

    public function write(string $type): WriteProcessor
    {
        return $this->writers[$type] ?? throw new RuntimeException(sprintf('No processor for %s.', $type));
    }
}
