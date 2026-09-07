<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WordPress\Manifest\StorageManifest;
use Eleph\WordPress\Manifest\StorageManifestBuilder;
use Eleph\WordPress\Migration\Introspector;
use Eleph\WordPress\Migration\SchemaInstaller;
use Eleph\WPGraphQL\Integration\WpGraphQL;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaInstaller::class)]
#[CoversClass(Introspector::class)]
final class SchemaInstallerTest extends TestCase
{
    private static ?StorageManifest $manifest = null;

    public function testAFreshInstallCreatesEveryTableIncludingTheJoinTables(): void
    {
        // The join table behind a many-to-many edge belongs to an edge rather than an
        // entity, so it was in no manifest at all — and a placement pointed at a table
        // nothing would ever create.
        $database = new FakeDatabase();

        $installer = new SchemaInstaller($database, $this->manifest());
        $plan = $installer->install();

        self::assertTrue($plan->isSafe());

        $created = array_map(
            static fn (string $sql): string => (string) preg_replace('/^CREATE TABLE `(\w+)`.*$/s', '$1', $sql),
            $plan->statements,
        );

        self::assertContains('wp_phe_post', $created);
        self::assertContains('wp_phe_comment', $created);
        self::assertContains('wp_phe_post_tags', $created);
    }

    public function testTheInstallationPrefixArrivesFromTheDatabaseNotTheBuild(): void
    {
        // A WordPress install can use any prefix and multisite uses one per site, so
        // the manifest holds unprefixed names and $wpdb supplies the rest.
        foreach ($this->tables(new FakeDatabase()) as $name => $table) {
            self::assertStringStartsWith('wp_', $name);
            self::assertSame($name, $table->name);
        }
    }

    public function testAnUpToDateSchemaPlansNothing(): void
    {
        // The round trip that matters: what the compiler emits, read back through
        // MariaDB's own vocabulary, must compare equal. Otherwise every boot refuses
        // every table with nothing actually wrong.
        $database = new FakeDatabase();
        $database->columns = $this->describe($database);
        $database->indexes = $this->describeIndexes($database);

        self::assertTrue((new SchemaInstaller($database, $this->manifest()))->plan()->isEmpty());
    }

    public function testAMissingColumnIsAddedAndAChangedOneIsRefused(): void
    {
        $database = new FakeDatabase();
        $columns = $this->describe($database);
        $indexes = $this->describeIndexes($database);

        // Drop a nullable column from the live schema, and change another's type.
        $columns['wp_phe_post'] = array_values(array_filter(
            $columns['wp_phe_post'],
            static fn (array $row): bool => 'published_at' !== $row['Field'],
        ));

        foreach ($columns['wp_phe_post'] as $index => $row) {
            if ('title' === $row['Field']) {
                $columns['wp_phe_post'][$index]['Type'] = 'varchar(50)';
            }
        }

        $database->columns = $columns;
        $database->indexes = $indexes;

        $plan = (new SchemaInstaller($database, $this->manifest()))->plan();

        self::assertStringContainsString('ADD COLUMN `published_at`', implode("\n", $plan->statements));

        $codes = array_map(static fn ($refusal): string => $refusal->code, $plan->refusals);

        self::assertSame(['column.changed'], $codes);
    }

    public function testNothingIsAppliedWhenAnythingIsRefused(): void
    {
        // A half-migrated schema is worse than an unmigrated one: the application
        // boots, some queries work, and the reason is two states from the spec.
        $database = new FakeDatabase();
        $columns = $this->describe($database);

        foreach ($columns['wp_phe_post'] as $index => $row) {
            if ('title' === $row['Field']) {
                $columns['wp_phe_post'][$index]['Type'] = 'varchar(50)';
            }
        }

        $database->columns = $columns;
        $database->indexes = $this->describeIndexes($database);

        $plan = (new SchemaInstaller($database, $this->manifest()))->install();

        self::assertFalse($plan->isSafe());
        self::assertSame([], array_filter(
            $database->statements,
            static fn (array $statement): bool => str_starts_with($statement['sql'], 'ALTER'),
        ));
    }

    /**
     * The live schema MariaDB would report for exactly what the manifest describes.
     *
     * @return array<string, list<array<string, scalar|null>>>
     */
    private function describe(FakeDatabase $database): array
    {
        $described = [];

        foreach ($this->tables($database) as $name => $table) {
            $rows = [];

            foreach ($table->columns as $column) {
                $rows[] = [
                    'Field' => $column->name,
                    // Lower case, and with the display widths MariaDB adds to integers.
                    'Type' => strtolower(str_replace('BIGINT', 'bigint(20)', $column->type)),
                    'Null' => $column->nullable ? 'YES' : 'NO',
                    'Key' => $column->autoIncrement ? 'PRI' : '',
                    'Default' => $column->default,
                    'Extra' => $column->autoIncrement ? 'auto_increment' : '',
                ];
            }

            $described[$name] = $rows;
        }

        return $described;
    }

    /**
     * @return array<string, list<array<string, scalar|null>>>
     */
    private function describeIndexes(FakeDatabase $database): array
    {
        $described = [];

        foreach ($this->tables($database) as $name => $table) {
            $rows = [['Key_name' => 'PRIMARY', 'Column_name' => 'id', 'Non_unique' => '0']];

            foreach ($table->indexes as $index) {
                foreach ($index->columns as $column) {
                    $rows[] = [
                        'Key_name' => $index->name,
                        'Column_name' => $column,
                        'Non_unique' => $index->unique ? '0' : '1',
                    ];
                }
            }

            $described[$name] = $rows;
        }

        return $described;
    }

    /**
     * @return array<string, \Eleph\WordPress\Sql\TableSchema>
     */
    private function tables(FakeDatabase $database): array
    {
        return (new SchemaInstaller($database, $this->manifest()))->tables();
    }

    private function manifest(): StorageManifest
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }

        $compiled = (new SchemaCompiler(integrations: new IntegrationRegistry(WpGraphQL::definition())))->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return self::$manifest = (new StorageManifestBuilder())->build($compiled->schema());
    }
}
