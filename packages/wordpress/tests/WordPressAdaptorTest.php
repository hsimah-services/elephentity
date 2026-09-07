<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\WordPress\Manifest\StorageManifestBuilder;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\FieldMap;
use Eleph\WordPress\Sql\TableSchema;
use Eleph\WordPress\WordPressAdaptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(WordPressAdaptor::class)]
final class WordPressAdaptorTest extends TestCase
{
    public function testItDeclaresWhatItCanAndCannotDo(): void
    {
        $capabilities = $this->adaptor(new FakeDatabase())->capabilities();

        self::assertTrue($capabilities->supports(Capability::Transactions));
        // No foreign keys: integrity is enforced in the framework, where the errors
        // are better and actions still run.
        self::assertFalse($capabilities->supports(Capability::ForeignKeys));
    }

    public function testGetSeparatesTheIdFromTheValues(): void
    {
        $database = new FakeDatabase();
        $database->rows = [['id' => 7, 'title' => 'Hello']];

        $record = $this->adaptor($database)->get('Post', EntityId::of(7));

        self::assertNotNull($record);
        self::assertTrue(EntityId::of(7)->equals($record->id));
        self::assertSame(['title' => 'Hello'], $record->values);
    }

    public function testGetManyIssuesOneQueryForEveryId(): void
    {
        // This is the batching primitive the edge loader stands on: one query for the
        // comments of fifty posts rather than fifty queries.
        $database = new FakeDatabase();

        $this->adaptor($database)->getMany('Post', [EntityId::of(1), EntityId::of(2), EntityId::of(3)]);

        self::assertCount(1, $database->statements);
        self::assertStringContainsString('IN (%d, %d, %d)', $database->statements[0]['sql']);
        self::assertSame([1, 2, 3], $database->statements[0]['bindings']);
    }

    public function testGetManyWithNoIdsNeverTouchesTheDatabase(): void
    {
        $database = new FakeDatabase();

        self::assertSame([], $this->adaptor($database)->getMany('Post', []));
        self::assertSame([], $database->statements);
    }

    public function testAnUnmappedEntityIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No table is mapped for entity "Ghost"');

        $this->adaptor(new FakeDatabase())->get('Ghost', EntityId::of(1));
    }

    public function testInsertsResolvePendingIdsToRealOnes(): void
    {
        // Auto-increment ids mean a row's identity is only known after the write, so
        // the batch hands back the mapping the unit of work needs to link the rest.
        $database = new FakeDatabase();
        $database->nextInsertId = 42;

        $pending = new PendingId('Post');
        $result = $this->adaptor($database)->write(new WriteBatch(
            new Insert('Post', $pending, ['title' => 'Hello']),
        ));

        self::assertTrue($result->wasAssigned($pending));
        self::assertTrue(EntityId::of(42)->equals($result->idFor($pending)));
        self::assertSame('wp_phe_post', $database->inserts[0]['table']);
    }

    public function testUpdatesOnlyTouchTheFieldsThatChanged(): void
    {
        $database = new FakeDatabase();

        $this->adaptor($database)->write(new WriteBatch(
            new Update('Post', EntityId::of(7), ['title' => 'New']),
        ));

        self::assertSame(
            'UPDATE `wp_phe_post` SET `title` = %s WHERE `id` = %d',
            $database->statements[0]['sql'],
        );
        self::assertSame(['New', 7], $database->statements[0]['bindings']);
    }

    public function testAnUpdateWithNothingToSayIssuesNoStatement(): void
    {
        $database = new FakeDatabase();

        $this->adaptor($database)->write(new WriteBatch(new Update('Post', EntityId::of(7), [])));

        self::assertSame([], $database->statements);
    }

    public function testATransactionCommitsOnSuccess(): void
    {
        $database = new FakeDatabase();

        $value = $this->adaptor($database)->transaction(static fn (): string => 'done');

        self::assertSame('done', $value);
        self::assertSame(['begin', 'commit'], $database->transactionLog);
    }

    public function testATransactionRollsBackAndRethrows(): void
    {
        $database = new FakeDatabase();

        $thrown = null;

        try {
            $this->adaptor($database)->transaction(static function (): mixed {
                throw new RuntimeException('a preCommit trigger said no');
            });
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        // A preCommit trigger throwing must take the whole commit with it.
        self::assertSame('a preCommit trigger said no', $thrown->getMessage());
        self::assertSame(['begin', 'rollback'], $database->transactionLog);
    }

    public function testAPageDropsTheProbeRowAndReportsThereIsMore(): void
    {
        // The compiler asks for one row more than the caller wanted; its presence
        // answers hasNextPage without a second query, and nothing above sees it.
        $database = new FakeDatabase();
        $database->rows = [
            ['id' => 1, 'created_at' => 'a'],
            ['id' => 2, 'created_at' => 'b'],
            ['id' => 3, 'created_at' => 'c'],
        ];

        $page = $this->adaptor($database)->query((new Criteria('Post'))->take(2));

        self::assertCount(2, $page->items);
        self::assertTrue($page->hasMore());
        self::assertSame(2, Offset::fromCursor($page->next)->value);
    }

    public function testTheLastPageOffersNoCursor(): void
    {
        $database = new FakeDatabase();
        $database->rows = [['id' => 1, 'created_at' => 'a']];

        $page = $this->adaptor($database)->query((new Criteria('Post'))->take(2));

        self::assertCount(1, $page->items);
        self::assertFalse($page->hasMore());
        self::assertNull($page->next);
    }

    public function testFieldNamesBecomeColumnNamesOnTheWayDown(): void
    {
        // Nothing above the adaptor should have to know this backend spells fields
        // in snake_case.
        $database = new FakeDatabase();

        $this->adaptor($database)->write(new WriteBatch(
            new Insert('Post', new PendingId('Post'), ['createdAt' => '2026-09-05 00:00:00']),
        ));

        self::assertSame(['created_at' => '2026-09-05 00:00:00'], $database->inserts[0]['values']);
    }

    public function testColumnNamesBecomeFieldNamesOnTheWayBack(): void
    {
        $database = new FakeDatabase();
        $database->rows = [['id' => 1, 'created_at' => '2026-09-05 00:00:00']];

        $record = $this->adaptor($database)->get('Post', EntityId::of(1));

        self::assertSame(['createdAt' => '2026-09-05 00:00:00'], $record?->values);
    }

    private function adaptor(FakeDatabase $database): WordPressAdaptor
    {
        $compiled = (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        return new WordPressAdaptor(
            $database,
            [
                'Post' => new TableSchema('wp_phe_post', [
                    'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
                    'title' => new Column('title', 'VARCHAR(255)'),
                ]),
            ],
            // Built from the manifest, the way WordPress::adaptor() builds it. There
            // used to be a fromSchema() shortcut and this was its only caller, which
            // made a runtime class carry a build-time signature for a test's sake.
            new FieldMap((new StorageManifestBuilder())->build($compiled->schema())->columns),
        );
    }
}
