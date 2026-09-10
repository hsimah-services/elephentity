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
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\WordPress\Manifest\StorageManifest;
use Eleph\WordPress\Manifest\StorageManifestBuilder;
use Eleph\WordPress\Manifest\StorageManifestExporter;
use Eleph\WordPress\WordPress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageManifest::class)]
#[CoversClass(StorageManifestBuilder::class)]
#[CoversClass(StorageManifestExporter::class)]
#[CoversClass(WordPress::class)]
final class StorageManifestTest extends TestCase
{
    private static ?StorageManifest $manifest = null;

    public function testItCarriesWhatTheAdaptorNeedsKeyedByEntity(): void
    {
        $manifest = $this->manifest();

        self::assertSame(['Author', 'Comment', 'Post', 'Tag'], array_keys($manifest->tables));
        self::assertSame('phe_post', $manifest->tables['Post']->name);
        self::assertSame('created_at', $manifest->columns['Post']['createdAt']);
        self::assertArrayHasKey('Post.comments', $manifest->placements);
    }

    public function testTableNamesAreUnprefixedUntilTheInstallationIsKnown(): void
    {
        // A WordPress install can use any prefix and multisite uses one per site, so
        // baking it in at build time would tie the manifest to a deployment.
        self::assertStringStartsNotWith('wp_', $this->manifest()->tables['Post']->name);
    }

    public function testPrefixingMovesTablesIndexesAndPlacementsTogether(): void
    {
        $prefixed = $this->manifest()->withPrefix('wp_');

        self::assertSame('wp_phe_post', $prefixed->tables['Post']->name);
        self::assertSame('wp_phe_comment', $prefixed->placements['Post.comments']->table);

        foreach ($prefixed->tables['Post']->indexes as $name => $index) {
            // Index names embed the table name, so they move with it.
            self::assertStringStartsWith('wp_', $name);
            self::assertSame($name, $index->name);
        }
    }

    public function testTheExportedManifestRebuildsWhatWasCompiled(): void
    {
        $exported = (new StorageManifestExporter())->export($this->manifest());

        $file = tempnam(sys_get_temp_dir(), 'eleph') . '.php';
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $exported);

        /** @var mixed $rebuilt */
        $rebuilt = require $file;
        unlink($file);

        self::assertInstanceOf(StorageManifest::class, $rebuilt);
        self::assertEquals($this->manifest(), $rebuilt);
    }

    public function testATaxonomyBackedEntityIsCarriedByTableNameNotByEdgePlacement(): void
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
            storage: new StorageDefinition('wordpress', 'tutorial'),
            sourceFile: 'entities/Tutorial.yml',
            edges: ['akas' => new EdgeDefinition('akas', 'Aka', Cardinality::Many, Origin::entity('entities/Tutorial.yml'))],
        );

        $schema = new Schema(
            project: new ProjectDefinition('test', 'wordpress', 'project.yml'),
            entities: ['Aka' => $aka, 'Tutorial' => $tutorial],
        );

        $manifest = (new StorageManifestBuilder())->build($schema);

        self::assertSame(['Aka' => 'aka'], $manifest->taxonomies);
        self::assertArrayHasKey('Tutorial.akas', $manifest->taxonomyPlacements);
        self::assertSame('aka', $manifest->taxonomyPlacements['Tutorial.akas']->taxonomy);
        self::assertArrayNotHasKey('Tutorial.akas', $manifest->placements);
        self::assertArrayNotHasKey('Aka', $manifest->tables);

        // Round-trips through export/rebuild the same as the rest of the manifest.
        $exported = (new StorageManifestExporter())->export($manifest);
        $file = tempnam(sys_get_temp_dir(), 'eleph') . '.php';
        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $exported);

        /** @var mixed $rebuilt */
        $rebuilt = require $file;
        unlink($file);

        self::assertEquals($manifest, $rebuilt);
    }

    private function manifest(): StorageManifest
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }

        $compiled = (new SchemaCompiler(integrations: TestIntegrations::registry()))
            ->compile(new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'));

        self::assertTrue($compiled->isSuccess());

        return self::$manifest = (new StorageManifestBuilder())->build($compiled->schema());
    }
}
