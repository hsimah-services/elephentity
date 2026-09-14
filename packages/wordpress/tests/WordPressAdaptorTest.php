<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\RelationKind;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\WordPress\Account\AccountFields;
use Eleph\WordPress\Account\AccountStorage;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\EdgePlacement;
use Eleph\WordPress\Sql\FieldMap;
use Eleph\WordPress\Sql\TableSchema;
use Eleph\WordPress\Taxonomy\TaxonomyPlacement;
use Eleph\WordPress\Taxonomy\TaxonomyStorage;
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

        $page = $this->adaptor($database)->query(Criteria::for('Post')->take(2));

        self::assertCount(2, $page->items);
        self::assertTrue($page->hasMore());
        self::assertSame(2, Offset::fromCursor($page->next)->value);
    }

    public function testTheLastPageOffersNoCursor(): void
    {
        $database = new FakeDatabase();
        $database->rows = [['id' => 1, 'created_at' => 'a']];

        $page = $this->adaptor($database)->query(Criteria::for('Post')->take(2));

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

    public function testGetDispatchesATaxonomyEntityToTerms(): void
    {
        $database = new FakeDatabase();
        $terms = new FakeTerms();
        $id = $terms->insert('aka', 'Sparky');

        $record = $this->taxonomyAdaptor($database, $terms)->get('Aka', EntityId::of($id));

        self::assertSame('Sparky', $record?->value('name'));
        self::assertSame([], $database->statements, 'A taxonomy entity never touches the SQL database.');
    }

    public function testQueryDispatchesATaxonomyEntityToTerms(): void
    {
        $database = new FakeDatabase();
        $terms = new FakeTerms();
        $terms->insert('aka', 'Sparky');

        $page = $this->taxonomyAdaptor($database, $terms)->query(Criteria::for('Aka'));

        self::assertCount(1, $page->items);
        self::assertSame([], $database->statements);
    }

    public function testCountDispatchesATaxonomyEntityToTerms(): void
    {
        $database = new FakeDatabase();
        $terms = new FakeTerms();
        $terms->insert('aka', 'Sparky');

        self::assertSame(1, $this->taxonomyAdaptor($database, $terms)->count(Criteria::for('Aka')));
        self::assertSame([], $database->statements);
    }

    public function testInsertOnATaxonomyEntityCreatesATermRatherThanARow(): void
    {
        $database = new FakeDatabase();
        $terms = new FakeTerms();
        $pending = new PendingId('Aka');

        $result = $this->taxonomyAdaptor($database, $terms)->write(new WriteBatch(
            new Insert('Aka', $pending, ['name' => 'Sparky']),
        ));

        self::assertSame('Sparky', $terms->name('aka', (int) $result->idFor($pending)->raw()));
        self::assertSame([], $database->inserts);
    }

    public function testLinkOnATaxonomyPlacedEdgeSetsATermRelationshipRatherThanSql(): void
    {
        $database = new FakeDatabase();
        $terms = new FakeTerms();
        $akaId = $terms->insert('aka', 'Sparky');

        $this->taxonomyAdaptor($database, $terms)->write(new WriteBatch(
            new Link('Tutorial', 'akas', EntityId::of(5), EntityId::of($akaId)),
        ));

        self::assertSame([$akaId], $terms->relationships['aka'][5]);
        self::assertSame([], $database->statements);
    }

    public function testUnlinkOnATaxonomyPlacedEdgeRemovesATermRelationship(): void
    {
        $database = new FakeDatabase();
        $terms = new FakeTerms();
        $akaId = $terms->insert('aka', 'Sparky');
        $terms->setTerms('aka', 5, [$akaId], append: false);

        $this->taxonomyAdaptor($database, $terms)->write(new WriteBatch(
            new Unlink('Tutorial', 'akas', EntityId::of(5), EntityId::of($akaId)),
        ));

        self::assertSame([], $terms->relationships['aka'][5]);
    }

    public function testAnOrdinaryEdgeOnTheSameEntityIsUnaffectedByTaxonomyPlacements(): void
    {
        // Tutorial itself is not taxonomy-backed — only its "akas" edge is diverted.
        // An unrelated write against Tutorial's own table must still reach SQL.
        $database = new FakeDatabase();

        $adaptor = new WordPressAdaptor(
            $database,
            ['Tutorial' => new TableSchema('wp_phe_tutorial', [
                'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
                'title' => new Column('title', 'VARCHAR(255)'),
            ])],
            new FieldMap([]),
            taxonomyPlacements: ['Tutorial.akas' => new TaxonomyPlacement('Tutorial', 'akas', 'Aka', 'aka')],
        );

        $adaptor->write(new WriteBatch(new Update('Tutorial', EntityId::of(1), ['title' => 'New'])));

        self::assertCount(1, $database->statements);
    }

    public function testGetDispatchesAnAccountEntityToUsers(): void
    {
        $database = new FakeDatabase();
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->meta[5]['bio'] = 'Hello';

        $record = $this->accountAdaptor($database, $users)->get('User', EntityId::of(5));

        self::assertSame('Hello', $record?->value('bio'));
        self::assertSame([], $database->statements, 'An account entity never touches the SQL database.');
    }

    public function testQueryDispatchesAnAccountEntityToUsers(): void
    {
        $database = new FakeDatabase();
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';

        $page = $this->accountAdaptor($database, $users)->query(Criteria::for('User'));

        self::assertCount(1, $page->items);
        self::assertSame([], $database->statements);
    }

    public function testCountDispatchesAnAccountEntityToUsers(): void
    {
        $database = new FakeDatabase();
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';

        self::assertSame(1, $this->accountAdaptor($database, $users)->count(Criteria::for('User')));
        self::assertSame([], $database->statements);
    }

    public function testUpdateOnAnAccountEntityWritesUsermetaRatherThanARow(): void
    {
        $database = new FakeDatabase();
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';

        $this->accountAdaptor($database, $users)->write(new WriteBatch(
            new Update('User', EntityId::of(5), ['bio' => 'Hello']),
        ));

        self::assertSame('Hello', $users->meta[5]['bio']);
        self::assertSame([], $database->statements);
    }

    public function testInsertOnAnAccountEntityIsRefused(): void
    {
        $database = new FakeDatabase();
        $users = new FakeUsers();

        $this->expectException(RuntimeException::class);

        $this->accountAdaptor($database, $users)->write(new WriteBatch(
            new Insert('User', new PendingId('User'), ['bio' => 'Hello']),
        ));
    }

    public function testQueryResolvesAToOneEdgeIntoAnAccountByReadingTheDeclaringRowsOwnKey(): void
    {
        // Instructor.user is a plain-column, many-to-one edge, so Instructor #4's own
        // row is what says which account it points at — the same thing a SQL join
        // would read if the account had a table to join into.
        $database = new FakeDatabase();
        $database->rows = [['id' => 4, 'user_id' => 5]];
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->meta[5]['bio'] = 'Hello';

        $page = $this->instructorAdaptor($database, $users)->query(
            Criteria::for('User')->linkedTo(EdgeFilter::along('Instructor', 'user', EntityId::of(4))),
        );

        self::assertCount(1, $page->items);
        self::assertSame('Hello', $page->items[0]->value('bio'));
        self::assertTrue(EntityId::of(5)->equals($page->items[0]->id));
        self::assertStringContainsString('wp_phe_instructor', $database->statements[0]['sql']);
    }

    public function testQueryProjectsTheParentColumnWhenSeveralInstructorsAreBatched(): void
    {
        // The batching entry point (CachingEdgeLoader::preload) resolves one edge for
        // many parents in a single call, and groups results by parent id — so a
        // resolved account row must still carry which instructor it came from.
        $database = new FakeDatabase();
        $database->rows = [['id' => 4, 'user_id' => 5], ['id' => 9, 'user_id' => 6]];
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->registered[6] = '2026-01-02 00:00:00';

        $page = $this->instructorAdaptor($database, $users)->query(
            Criteria::for('User')->linkedTo(EdgeFilter::along('Instructor', 'user', EntityId::of(4), EntityId::of(9))),
        );

        self::assertCount(2, $page->items);
        self::assertSame(4, $page->items[0]->value(EdgeFilter::PARENT_COLUMN));
        self::assertSame(9, $page->items[1]->value(EdgeFilter::PARENT_COLUMN));
    }

    public function testQueryIntoAnAccountWithNoLinkedRowComesBackEmpty(): void
    {
        // An instructor whose FK never resolves (e.g. it was cleared) is not an error
        // — it is the same "nothing there" a join would report.
        $database = new FakeDatabase();
        $database->rows = [['id' => 4, 'user_id' => null]];
        $users = new FakeUsers();

        $page = $this->instructorAdaptor($database, $users)->query(
            Criteria::for('User')->linkedTo(EdgeFilter::along('Instructor', 'user', EntityId::of(4))),
        );

        self::assertSame([], $page->items);
    }

    public function testCountResolvesAToOneEdgeIntoAnAccountTheSameWay(): void
    {
        $database = new FakeDatabase();
        $database->rows = [['id' => 4, 'user_id' => 5]];
        $users = new FakeUsers();
        $users->registered[5] = '2026-01-01 00:00:00';

        $count = $this->instructorAdaptor($database, $users)->count(
            Criteria::for('User')->linkedTo(EdgeFilter::along('Instructor', 'user', EntityId::of(4))),
        );

        self::assertSame(1, $count);
    }

    public function testAReversedLinkIntoAnAccountStillFallsThroughToAccountStorage(): void
    {
        // A mapped placement exists for Instructor.user, but this criteria asks for it
        // backwards — a shape with no column to read on either table — so the
        // fallback's own, narrower, refusal still applies.
        $database = new FakeDatabase();
        $users = new FakeUsers();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An account-backed entity supports listing and paging only');

        $this->instructorAdaptor($database, $users)->query(
            Criteria::for('User')->linkedTo(EdgeFilter::back('Instructor', 'user', EntityId::of(4))),
        );
    }

    private function instructorAdaptor(FakeDatabase $database, FakeUsers $users): WordPressAdaptor
    {
        return $this->accountAdaptor($database, $users, [
            'Instructor.user' => new EdgePlacement(
                'Instructor',
                'user',
                'User',
                RelationKind::ManyToOne,
                'wp_phe_instructor',
                'user_id',
            ),
        ]);
    }

    /**
     * @param array<string, EdgePlacement> $placements
     */
    private function accountAdaptor(FakeDatabase $database, FakeUsers $users, array $placements = []): WordPressAdaptor
    {
        return new WordPressAdaptor(
            $database,
            [],
            new FieldMap(['User' => ['bio' => 'bio']]),
            placements: $placements,
            accounts: ['User' => new AccountFields('User', null, null)],
            account: new AccountStorage($users),
        );
    }

    private function taxonomyAdaptor(FakeDatabase $database, FakeTerms $terms): WordPressAdaptor
    {
        return new WordPressAdaptor(
            $database,
            [],
            new FieldMap([]),
            taxonomies: ['Aka' => 'aka'],
            taxonomyPlacements: ['Tutorial.akas' => new TaxonomyPlacement('Tutorial', 'akas', 'Aka', 'aka')],
            taxonomy: new TaxonomyStorage($terms),
        );
    }

    private function adaptor(FakeDatabase $database): WordPressAdaptor
    {
        return new WordPressAdaptor(
            $database,
            [
                'Post' => new TableSchema('wp_phe_post', [
                    'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
                    'title' => new Column('title', 'VARCHAR(255)'),
                ]),
            ],
            // Hand-built rather than derived from a compiled spec: this is the same
            // FieldMap WordPress::adaptor() builds from the manifest, but a fixed map
            // of exactly the fields this file's tests touch is enough to prove the
            // adaptor translates them, and does not require compiling a spec here.
            new FieldMap(['Post' => ['title' => 'title', 'createdAt' => 'created_at']]),
        );
    }
}
