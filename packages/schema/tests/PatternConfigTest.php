<?php

declare(strict_types=1);

namespace PheFr\Schema\Tests;

use PheFr\Schema\Error\CompilationResult;
use PheFr\Schema\Pattern\ConfigResolver;
use PheFr\Schema\SchemaCompiler;
use PheFr\Schema\SpecSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A pattern declares what it accepts; an entity supplies it; the compiler checks the
 * one against the other.
 *
 * The point of the arrangement is that the core validates `visibility` against the
 * pattern's own list of permitted values while having no idea what a visibility is.
 * Nothing in this package knows the word "WordPress".
 */
#[CoversClass(ConfigResolver::class)]
final class PatternConfigTest extends TestCase
{
    public function testSuppliedValuesWinAndTheRestAreDefaulted(): void
    {
        $entity = $this->compile('config')->schema()->entity('Configured');

        self::assertNotNull($entity);
        self::assertSame('public', $entity->configured('visibility'));
        self::assertSame(['title'], $entity->configured('supports'));
        // Untouched, so the pattern's default stands.
        self::assertSame(10, $entity->configured('weight'));
    }

    public function testAnEntityThatConfiguresNothingStillGetsEveryDefault(): void
    {
        // Downstream never has to reason about an absent key.
        $entity = $this->compile('config')->schema()->entity('Defaulted');

        self::assertNotNull($entity);
        self::assertSame('private', $entity->configured('visibility'));
        self::assertSame([], $entity->configured('supports'));
        self::assertSame(10, $entity->configured('weight'));
    }

    public function testANullableParameterWithNoDefaultResolvesToNull(): void
    {
        $entity = $this->compile('config')->schema()->entity('Defaulted');

        self::assertNotNull($entity);
        self::assertArrayHasKey('adminMenu', $entity->config);
        self::assertNull($entity->configured('adminMenu'));
    }

    public function testAnUnknownKeyIsRefusedAndTheMessageListsWhatIsAccepted(): void
    {
        $errors = $this->errorsFor('config-broken');

        self::assertArrayHasKey('config.unknown', $errors);
        self::assertStringContainsString('It accepts: visibility, supports', $errors['config.unknown']);
    }

    public function testAValueOutsideTheDeclaredSetIsRefused(): void
    {
        $errors = $this->errorsFor('config-broken');

        self::assertSame(
            'Configuration "visibility" expects one of public, private.',
            $errors['config.invalidValue'],
        );
    }

    public function testConfiguringAPatternTheEntityDoesNotUseIsRefused(): void
    {
        self::assertArrayHasKey('config.unusedPattern', $this->errorsFor('config-broken'));
    }

    public function testTwoPatternsDeclaringTheSameKeyIsRefused(): void
    {
        // A resolved value must have exactly one source, or a consumer reading it has
        // no way to know which pattern it came from.
        $errors = $this->errorsFor('config-broken');

        self::assertStringContainsString(
            'Patterns Platform and Rival both declare configuration "visibility"',
            $errors['config.collision'],
        );
    }

    /**
     * @return array<string, string> code => message
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
        return (new SchemaCompiler())->compile(
            new SpecSource(__DIR__ . '/fixtures/' . $fixture),
        );
    }
}
