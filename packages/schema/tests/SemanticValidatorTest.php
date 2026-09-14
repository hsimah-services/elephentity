<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Ir\StorageDefinition;
use Eleph\Schema\SemanticValidator;
use Eleph\Schema\Storage\StorageRuleRegistry;
use Eleph\Schema\Storage\StorageRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Handle validation is the driver's own rule, declared over the wire and pooled into a
 * registry the validator reads (#52 G3) — it used to be `'wordpress' === $driver`
 * branching inside the driver-agnostic compiler.
 */
#[CoversClass(SemanticValidator::class)]
final class SemanticValidatorTest extends TestCase
{
    public function testADriverDeclaringNoRulesValidatesWithoutHandleErrors(): void
    {
        $errors = (new SemanticValidator())->validate($this->schema('custom', 'ThisHandleIsFarTooLongAndAlsoUppercase'));

        self::assertSame([], $errors);
    }

    public function testATooLongHandleFailsWithTheLimitSourcedFromTheDriver(): void
    {
        $registry = new StorageRuleRegistry(['acme' => new StorageRules(maxHandleLength: 5)]);

        $errors = (new SemanticValidator($registry))->validate($this->schema('acme', 'toolonghandle'));

        self::assertCount(1, $errors);
        self::assertSame('storage.handleTooLong', $errors[0]->code);
        self::assertStringContainsString('driver "acme" caps handles at 5', $errors[0]->message);
    }

    public function testAHandleNotMatchingTheDriversPatternFailsNamingTheDriver(): void
    {
        $registry = new StorageRuleRegistry(['acme' => new StorageRules(handlePattern: '/^[a-z]+$/')]);

        $errors = (new SemanticValidator($registry))->validate($this->schema('acme', 'Not_Valid'));

        self::assertCount(1, $errors);
        self::assertSame('storage.handleInvalid', $errors[0]->code);
        self::assertStringContainsString('driver "acme"', $errors[0]->message);
    }

    public function testAReservedHandleFailsNamingTheDriver(): void
    {
        $registry = new StorageRuleRegistry(['acme' => new StorageRules(reservedHandles: ['taken'])]);

        $errors = (new SemanticValidator($registry))->validate($this->schema('acme', 'taken'));

        self::assertCount(1, $errors);
        self::assertSame('storage.handleReserved', $errors[0]->code);
        self::assertStringContainsString('reserved by driver "acme"', $errors[0]->message);
    }

    private function schema(string $driver, string $handle): Schema
    {
        $entity = new EntityDefinition(
            name: 'Widget',
            storage: new StorageDefinition($driver, 'widget', handle: $handle),
            sourceFile: 'entities/Widget.yml',
        );

        return new Schema(
            project: new ProjectDefinition('Test', $driver, 'project.yml'),
            entities: ['Widget' => $entity],
        );
    }
}
