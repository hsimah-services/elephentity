<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Settings no entity can sensibly vary live once, at the project.
 */
#[CoversClass(ProjectDefinition::class)]
final class ProjectSpecTest extends TestCase
{
    public function testTheDriverComesFromTheProjectAndReachesEveryEntity(): void
    {
        // A unit of work has one adaptor, so letting entities each declare a driver
        // would be inviting a lie the format cannot honour.
        $schema = $this->compile('valid')->schema();

        self::assertSame('wordpress', $schema->project->driver);

        foreach ($schema->entities as $entity) {
            self::assertSame('wordpress', $entity->storage->driver, $entity->name);
        }
    }

    public function testTheProjectPrefixIsResolvedIntoEveryTableName(): void
    {
        // Applied at compile time, so conflict detection, DDL and queries all see one
        // resolved name rather than each having to remember to prepend it.
        $schema = $this->compile('prefixed')->schema();

        self::assertSame('shop_', $schema->project->tablePrefix);
        self::assertSame('shop_widget', $schema->entity('Widget')?->storage->table);
    }

    public function testAProjectWithoutASpecCannotCompile(): void
    {
        $result = $this->compile('no-project');

        self::assertFalse($result->isSuccess());
        self::assertSame('project.missing', $result->errors[0]->code);
        self::assertStringContainsString('it declares the storage driver', $result->errors[0]->message);
    }

    public function testAnEntityMayNotDeclareADriver(): void
    {
        // The schema rejects it outright, rather than the compiler having to decide
        // which of two answers wins.
        $codes = array_map(
            static fn ($error) => $error->code,
            $this->compile('entity-driver')->errors,
        );

        self::assertContains('spec.invalid', $codes);
    }

    private function compile(string $fixture): CompilationResult
    {
        return (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/fixtures/' . $fixture),
        );
    }
}
