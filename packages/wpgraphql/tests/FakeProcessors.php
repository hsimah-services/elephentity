<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\Type\ReadProcessor;
use Eleph\Runtime\Type\WriteProcessor;
use Eleph\Runtime\Verification\Verification;
use RuntimeException;

/**
 * A registry holding one processor, for the value type the fixture declares.
 *
 * Only `write()` matters here: reading a Money out of the graph means turning the
 * domain object back into the primitive it is stored as, which is the write
 * processor's job on every other path too.
 */
final readonly class FakeProcessors implements ProcessorRegistry
{
    public function has(string $type): bool
    {
        return 'Money' === $type;
    }

    public function read(string $type): ReadProcessor
    {
        throw new RuntimeException('Reading is not exercised here.');
    }

    public function write(string $type): WriteProcessor
    {
        if (!$this->has($type)) {
            throw new RuntimeException(sprintf('No processor for %s.', $type));
        }

        return new class () implements WriteProcessor {
            public function verify(mixed $value, MutationContext $context): Verification
            {
                return Verification::ok();
            }

            public function write(mixed $value): int
            {
                if (is_object($value) && property_exists($value, 'cents') && is_int($value->cents)) {
                    return $value->cents;
                }

                throw new RuntimeException('That is not a Money.');
            }
        };
    }
}
