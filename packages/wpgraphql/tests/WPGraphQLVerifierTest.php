<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

use Eleph\Runtime\Conformance\Severity;
use Eleph\Runtime\Conformance\Signal;
use Eleph\WPGraphQL\Manifest\Manifest;
use Eleph\WPGraphQL\Verification\WPGraphQLVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ported from the conformance checker `eleph check` used to build itself, in-process,
 * from a freshly compiled spec (#52 G3). It now implements the runtime's `Verifier`
 * interface and answers the same questions off the manifest it is handed.
 */
#[CoversClass(WPGraphQLVerifier::class)]
final class WPGraphQLVerifierTest extends TestCase
{
    public function testConformanceFailsLoudlyWhenTheEntityClassIsMissing(): void
    {
        // The case the IR cannot see: a spec that changed and code that was not
        // regenerated.
        $signals = (new WPGraphQLVerifier($this->manifest()))->verify(
            static fn (string $entity): string => 'Nonexistent\\' . $entity,
        );

        self::assertNotSame([], $signals);
        self::assertSame(Severity::Error, $signals[0]->severity);
        self::assertStringContainsString('Run `eleph generate`', $signals[0]->message);
    }

    public function testConformanceFailsWhenAnAccessorIsMissing(): void
    {
        $signals = (new WPGraphQLVerifier($this->manifest()))->verify(
            static fn (): string => ConformanceSubject::class,
        );

        $joined = implode("\n", array_map(
            static fn (Signal $signal): string => $signal->message,
            $signals,
        ));

        // The subject has getTitle() but not getPrice().
        self::assertStringNotContainsString('Post.title resolves', $joined);
        self::assertStringContainsString('Post.price resolves', $joined);

        foreach ($signals as $signal) {
            self::assertSame(Severity::Error, $signal->severity);
        }
    }

    private function manifest(): Manifest
    {
        return Fixture::manifest();
    }
}
