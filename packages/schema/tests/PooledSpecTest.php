<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\Spec\RawSpec;
use Eleph\Schema\Spec\SpecKind;
use Eleph\Schema\SpecSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A builder can ship a pattern or type via `describe`, so a project may `use:` it with
 * nothing under `spec/patterns/` or `spec/types/` on disk. Pooled documents get
 * `SchemaValidator` and everything after it exactly as a project-local one would —
 * this proves that, and proves a project document colliding with a pooled one is
 * sealed the same way two project documents are.
 */
#[CoversClass(SchemaCompiler::class)]
final class PooledSpecTest extends TestCase
{
    public function testAnEntityCanUseAPooledPatternWithNoFileOnDisk(): void
    {
        $pooled = new RawSpec(SpecKind::Pattern, 'elephentity/codegen-wordpress:patterns/Taxonomy.yml', [
            'pattern' => 'Taxonomy',
            'requires' => ['driver' => 'wordpress'],
            'fields' => ['name' => ['type' => 'string']],
        ]);

        $result = (new SchemaCompiler(pooledPatterns: [$pooled]))->compile(
            new SpecSource(__DIR__ . '/fixtures/pooled'),
        );

        self::assertTrue($result->isSuccess(), implode("\n", array_map(
            static fn ($error) => $error->describe(),
            $result->errors,
        )));

        $entity = $result->schema()->entity('Term');

        self::assertNotNull($entity);
        self::assertArrayHasKey('name', $entity->fields);
        self::assertSame(['Taxonomy'], $entity->appliedPatterns);
    }

    public function testAPooledTypeIsPooledTheSameWay(): void
    {
        $pooled = new RawSpec(SpecKind::Type, 'elephentity/codegen-php:types/PostStatus.yml', [
            'type' => 'PostStatus',
            'primitive' => 'string',
            'values' => ['draft', 'published'],
        ]);

        $result = (new SchemaCompiler(pooledTypes: [$pooled]))->compile(
            new SpecSource(__DIR__ . '/fixtures/pooled-empty'),
        );

        self::assertTrue($result->isSuccess());
        self::assertNotNull($result->schema()->type('PostStatus'));
    }

    public function testAProjectPatternCollidingWithAPooledOneNamesThePackageAsIncumbent(): void
    {
        $pooled = new RawSpec(SpecKind::Pattern, 'elephentity/codegen-wordpress:patterns/Taxonomy.yml', [
            'pattern' => 'Taxonomy',
            'fields' => ['name' => ['type' => 'string']],
        ]);

        $result = (new SchemaCompiler(pooledPatterns: [$pooled]))->compile(
            new SpecSource(__DIR__ . '/fixtures/pooled-collision'),
        );

        self::assertFalse($result->isSuccess());

        $duplicate = array_values(array_filter(
            $result->errors,
            static fn ($error) => 'pattern.duplicate' === $error->code,
        ));

        self::assertCount(1, $duplicate);
        self::assertStringContainsString(
            'elephentity/codegen-wordpress:patterns/Taxonomy.yml',
            $duplicate[0]->message,
        );
    }

    public function testAMalformedPooledDocumentFailsTheSameWayAProjectOneWould(): void
    {
        $pooled = new RawSpec(SpecKind::Pattern, 'elephentity/codegen-wordpress:patterns/Taxonomy.yml', [
            'pattern' => 'Taxonomy',
            'notAField' => true,
        ]);

        $result = (new SchemaCompiler(pooledPatterns: [$pooled]))->compile(
            new SpecSource(__DIR__ . '/fixtures/pooled-empty'),
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('elephentity/codegen-wordpress:patterns/Taxonomy.yml', $result->errors[0]->file);
    }
}
