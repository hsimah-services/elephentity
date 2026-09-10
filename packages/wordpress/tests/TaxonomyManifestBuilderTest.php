<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Origin;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Ir\StorageDefinition;
use Eleph\Schema\Ir\TypeReference;
use Eleph\WordPress\Manifest\PostTypeManifestBuilder;
use Eleph\WordPress\Manifest\TaxonomyManifestBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxonomyManifestBuilder::class)]
#[CoversClass(PostTypeManifestBuilder::class)]
final class TaxonomyManifestBuilderTest extends TestCase
{
    public function testATaxonomyGetsObjectTypesFromWhicheverPostTypesEdgeToIt(): void
    {
        $taxonomies = (new TaxonomyManifestBuilder())->build($this->schema());

        self::assertArrayHasKey('aka', $taxonomies);
        self::assertSame(['tutorial'], $taxonomies['aka']['object_type']);
    }

    public function testATaxonomyWithNothingPointingAtItStillGetsRegisteredEmpty(): void
    {
        $entity = new EntityDefinition(
            name: 'Aka',
            storage: new StorageDefinition('wordpress', 'aka', handle: 'aka'),
            sourceFile: 'entities/Aka.yml',
            config: ['taxonomy' => true],
        );

        $schema = new Schema(
            project: new ProjectDefinition('test', 'wordpress', 'project.yml'),
            entities: ['Aka' => $entity],
        );

        $taxonomies = (new TaxonomyManifestBuilder())->build($schema);

        self::assertSame([], $taxonomies['aka']['object_type']);
    }

    public function testLabelsDeriveFromTheEntityNameByDefault(): void
    {
        $taxonomies = (new TaxonomyManifestBuilder())->build($this->schema());

        /** @var array<string, string> $labels */
        $labels = $taxonomies['aka']['labels'];

        self::assertSame('Akas', $labels['name']);
        self::assertSame('Aka', $labels['singular_name']);
    }

    public function testATaxonomyEntityIsExcludedFromThePostTypeManifest(): void
    {
        // A taxonomy entity's handle names a taxonomy slug, not a post type slug —
        // registering it as both would collide in WordPress's own registry.
        $postTypes = (new PostTypeManifestBuilder())->build($this->schema());

        self::assertArrayNotHasKey('aka', $postTypes);
        self::assertArrayHasKey('tutorial', $postTypes);
    }

    private function schema(): Schema
    {
        $aka = new EntityDefinition(
            name: 'Aka',
            storage: new StorageDefinition('wordpress', 'aka', handle: 'aka'),
            sourceFile: 'entities/Aka.yml',
            fields: ['name' => new FieldDefinition('name', TypeReference::primitive(Primitive::String), Origin::entity('entities/Aka.yml'))],
            config: ['taxonomy' => true],
        );

        $tutorial = new EntityDefinition(
            name: 'Tutorial',
            storage: new StorageDefinition('wordpress', 'tutorial', handle: 'tutorial'),
            sourceFile: 'entities/Tutorial.yml',
            edges: ['akas' => new EdgeDefinition('akas', 'Aka', Cardinality::Many, Origin::entity('entities/Tutorial.yml'))],
        );

        return new Schema(
            project: new ProjectDefinition('test', 'wordpress', 'project.yml'),
            entities: ['Aka' => $aka, 'Tutorial' => $tutorial],
        );
    }
}
