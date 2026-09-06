<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The compiler's value is mostly in what it refuses, so the refusals are the tests.
 */
#[CoversNothing]
final class CompilationFailureTest extends TestCase
{
    public function testSealedPatternsRejectARedeclaredField(): void
    {
        $codes = $this->codesFor('collision');

        self::assertContains('pattern.collision', $codes);
    }

    public function testPatternCyclesAreReportedRatherThanHungOn(): void
    {
        self::assertContains('pattern.cycle', $this->codesFor('cycle'));
    }

    public function testAPatternRefusesADriverItDoesNotSupport(): void
    {
        $result = $this->compile('driver');

        self::assertContains('pattern.driverMismatch', $this->codes($result));
        self::assertStringContainsString(
            'requires driver "wordpress" but Widget uses "postgres"',
            implode("\n", array_map(static fn ($e) => $e->message, $result->errors)),
        );
    }

    public function testSemanticFailuresAreAllReportedInOneRun(): void
    {
        $codes = $this->codesFor('semantic');

        // id is implicit and may not be declared.
        self::assertContains('field.reserved', $codes);
        // A wordpress handle must be lowercase and within the post type slug limit.
        self::assertContains('storage.handleTooLong', $codes);
        self::assertContains('storage.handleInvalid', $codes);
        // A unique string wider than the index limit must be narrowed, never
        // silently prefix-indexed.
        self::assertContains('field.indexTooWide', $codes);
        // maxLength only sizes strings.
        self::assertContains('field.maxLengthNotSizable', $codes);
        // Money is never declared in types/.
        self::assertContains('type.unknown', $codes);
        // An enum field with no values.
        self::assertContains('field.enumWithoutValues', $codes);
        // A derived inverse cannot be pluralised.
        self::assertContains('edge.inverseNotDerivable', $codes);
        // Edge and query targets must exist.
        self::assertContains('edge.unknownTarget', $codes);
        self::assertContains('query.unknownReturn', $codes);
        // An action cannot declare it writes a field the entity lacks.
        self::assertContains('action.unknownWrite', $codes);
    }

    public function testAFailedCompilationExposesNoSchema(): void
    {
        self::assertFalse($this->compile('cycle')->isSuccess());
    }

    /**
     * @return list<string>
     */
    private function codesFor(string $fixture): array
    {
        return $this->codes($this->compile($fixture));
    }

    /**
     * @return list<string>
     */
    private function codes(CompilationResult $result): array
    {
        return array_values(array_map(static fn ($error) => $error->code, $result->errors));
    }

    private function compile(string $fixture): CompilationResult
    {
        return (new SchemaCompiler())->compile(
            new SpecSource(__DIR__ . '/fixtures/' . $fixture),
        );
    }
}
