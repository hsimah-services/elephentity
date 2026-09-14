<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Storage\Testing;

use Eleph\Runtime\Storage\Testing\AdaptorConformance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `elephentity/memory`'s own tests prove a real adaptor passes this suite; this proves
 * the suite itself is not a formality — an adaptor that satisfies the interface and
 * nothing else fails it, loudly and specifically.
 */
#[CoversClass(AdaptorConformance::class)]
final class AdaptorConformanceTest extends TestCase
{
    public function testAnAdaptorThatNeverReadsBackWhatItWroteFails(): void
    {
        $failures = (new AdaptorConformance())->check(new BrokenAdaptor(), 'Widget', 'owner', 'Gadget');

        self::assertNotSame([], $failures);
        self::assertTrue(
            0 !== count(array_filter($failures, static fn (string $f): bool => str_contains($f, 'get()'))),
            'Expected a failure naming get(), got: ' . implode("\n", $failures),
        );
    }
}
