<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Type;

use Eleph\Runtime\Type\NullProcessorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NullProcessorRegistry::class)]
final class NullProcessorRegistryTest extends TestCase
{
    public function testItHasNoType(): void
    {
        $registry = new NullProcessorRegistry();

        self::assertFalse($registry->has('Money'));
    }

    public function testReadingRefusesByName(): void
    {
        $registry = new NullProcessorRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Money declares no read processor.');

        $registry->read('Money');
    }

    public function testWritingRefusesByName(): void
    {
        $registry = new NullProcessorRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Money declares no write processor.');

        $registry->write('Money');
    }
}
