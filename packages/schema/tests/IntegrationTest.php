<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\Integration\IntegrationResolver;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Integrations are opt-in twice: the project says what it speaks, each entity says
 * what it exposes.
 *
 * The core validates all of it while knowing nothing about any particular integration
 * — the definitions arrive from whichever packages are installed.
 */
#[CoversClass(IntegrationResolver::class)]
final class IntegrationTest extends TestCase
{
    public function testAnEntityIsExposedOnlyIfItSaysSo(): void
    {
        // Adding an entity should never silently widen a public API.
        $schema = $this->compile('integrations')->schema();

        self::assertNotNull($schema->entity('Shown')?->exposedVia('wpgraphql'));
        self::assertNull($schema->entity('Hidden')?->exposedVia('wpgraphql'));
    }

    public function testTheProjectRecordsWhatItSpeaks(): void
    {
        $project = $this->compile('integrations')->schema()->project;

        self::assertTrue($project->speaks('wpgraphql'));
        self::assertFalse($project->speaks('rest'));
    }

    public function testEntitySettingsAreResolvedAgainstTheIntegrationsOwnDeclaration(): void
    {
        $exposure = $this->compile('integrations')->schema()->entity('Shown')?->exposedVia('wpgraphql');

        self::assertSame(['plural' => 'Showns', 'singular' => 'Shown'], $exposure);
    }

    public function testAnUnknownIntegrationNamesWhatIsInstalled(): void
    {
        $errors = $this->errorsFor('integration-broken');

        self::assertArrayHasKey('integration.unknown', $errors);
        self::assertStringContainsString('Installed: wpgraphql.', $errors['integration.unknown']);
    }

    public function testAnUnknownSettingIsRefused(): void
    {
        self::assertArrayHasKey('config.unknown', $this->errorsFor('integration-broken'));
    }

    public function testAnEntityCannotOptIntoSomethingTheProjectDoesNotEnable(): void
    {
        // The second opt-in is the point: without it an entity could widen the
        // project's surface on its own.
        self::assertStringContainsString(
            'which the project does not enable',
            $this->errorsFor('integration-disabled')['integration.notEnabled'],
        );
    }

    public function testASettingWithNoDefaultAndNoNullMustBeSupplied(): void
    {
        // The fixture supplies singular and omits plural.
        $errors = $this->errorsFor('integration-broken');

        self::assertStringContainsString(
            'requires configuration "plural"',
            $errors['config.required'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function errorsFor(string $fixture): array
    {
        $errors = [];

        foreach ($this->compile($fixture)->errors as $error) {
            $errors[$error->code] ??= $error->message;
        }

        return $errors;
    }

    private function compile(string $fixture): CompilationResult
    {
        return (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/fixtures/' . $fixture),
        );
    }
}
