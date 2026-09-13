<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\WordPress\Account\AccountFields;
use Eleph\WordPress\Account\AccountStorage;
use Eleph\WordPress\Sql\FieldMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AccountStorage::class)]
final class AccountStorageTest extends TestCase
{
    public function testGetReturnsNullWhenWordPressHasNoSuchUser(): void
    {
        [, $storage, $fields, $map] = $this->storage();

        self::assertNull($storage->get('User', $fields, $map, EntityId::of(5)));
    }

    public function testGetReturnsARecordForARegisteredUserEvenWithNoUsermetaYet(): void
    {
        [$users, $storage, $fields, $map] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';

        $record = $storage->get('User', $fields, $map, EntityId::of(5));

        self::assertNotNull($record);
        self::assertSame(5, $record->id->raw());
        self::assertNull($record->value('bio'));
    }

    public function testGetReadsUsermetaFieldsThroughTheColumnNames(): void
    {
        [$users, $storage, $fields, $map] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->meta[5]['bio'] = 'Hello';

        $record = $storage->get('User', $fields, $map, EntityId::of(5));

        self::assertSame('Hello', $record?->value('bio'));
    }

    public function testGetAnswersACreatedFieldFromRegistrationDate(): void
    {
        [$users, $storage, $fields, $map] = $this->storage(created: 'joinedAt');
        $users->registered[5] = '2026-01-01 00:00:00';

        $record = $storage->get('User', $fields, $map, EntityId::of(5));

        self::assertSame('2026-01-01 00:00:00', $record?->value('joinedAt'));
    }

    public function testGetFallsBackToRegistrationDateForAnUnmodifiedField(): void
    {
        [$users, $storage, $fields, $map] = $this->storage(modified: 'touchedAt');
        $users->registered[5] = '2026-01-01 00:00:00';

        $record = $storage->get('User', $fields, $map, EntityId::of(5));

        self::assertSame('2026-01-01 00:00:00', $record?->value('touchedAt'));
    }

    public function testGetReadsAModifiedFieldFromUsermetaOnceWritten(): void
    {
        [$users, $storage, $fields, $map] = $this->storage(modified: 'touchedAt');
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->meta[5]['touched_at'] = '2026-02-02 00:00:00';

        $record = $storage->get('User', $fields, $map, EntityId::of(5));

        self::assertSame('2026-02-02 00:00:00', $record?->value('touchedAt'));
    }

    public function testGetManySilentlyDropsUnregisteredIds(): void
    {
        [$users, $storage, $fields, $map] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';

        $records = $storage->getMany('User', $fields, $map, [EntityId::of(5), EntityId::of(999)]);

        self::assertCount(1, $records);
        self::assertSame(5, $records[0]->id->raw());
    }

    public function testInsertIsRefused(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->insert();
    }

    public function testDeleteIsRefused(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->delete();
    }

    public function testUpdateWritesUsermetaThroughTheColumnName(): void
    {
        [$users, $storage, $fields, $map] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';

        $storage->update($fields, $map, new Update('User', EntityId::of(5), ['bio' => 'Hello']));

        self::assertSame('Hello', $users->meta[5]['bio']);
    }

    public function testUpdateNeverWritesTheCreatedField(): void
    {
        [$users, $storage, $fields, $map] = $this->storage(created: 'joinedAt');
        $users->registered[5] = '2026-01-01 00:00:00';

        // The unit of work only stamps Managed::Created on insert, which this class
        // refuses — so a value arriving here at all would already be a caller bug —
        // but writing it must stay a no-op rather than a wrong usermeta entry.
        $storage->update($fields, $map, new Update('User', EntityId::of(5), ['joinedAt' => '2099-01-01 00:00:00']));

        self::assertArrayNotHasKey('joined_at', $users->meta[5] ?? []);
    }

    public function testQueryListsEveryRegisteredUser(): void
    {
        [$users, $storage, $fields, $map] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->registered[6] = '2026-01-02 00:00:00';

        $page = $storage->query('User', $fields, $map, Criteria::for('User'));

        self::assertCount(2, $page->items);
        self::assertFalse($page->hasMore());
    }

    public function testQueryPaginatesAndReportsMore(): void
    {
        [$users, $storage, $fields, $map] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->registered[6] = '2026-01-02 00:00:00';
        $users->registered[7] = '2026-01-03 00:00:00';

        $page = $storage->query('User', $fields, $map, Criteria::for('User')->take(2));

        self::assertCount(2, $page->items);
        self::assertTrue($page->hasMore());

        $next = $storage->query('User', $fields, $map, Criteria::for('User')->take(2, $page->next));

        self::assertCount(1, $next->items);
        self::assertFalse($next->hasMore());
    }

    public function testQueryFieldFiltersAreRefused(): void
    {
        [, $storage, $fields, $map] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->query('User', $fields, $map, Criteria::for('User')->where(Filter::equals('bio', 'x')));
    }

    public function testCountCountsEveryRegisteredUser(): void
    {
        [$users, $storage] = $this->storage();
        $users->registered[5] = '2026-01-01 00:00:00';
        $users->registered[6] = '2026-01-02 00:00:00';

        self::assertSame(2, $storage->count(Criteria::for('User')));
    }

    /**
     * @return array{0: FakeUsers, 1: AccountStorage, 2: AccountFields, 3: FieldMap}
     */
    private function storage(?string $created = null, ?string $modified = null): array
    {
        $users = new FakeUsers();
        $fields = new AccountFields('User', $created, $modified);

        $columns = ['bio' => 'bio'];

        if (null !== $created) {
            $columns[$created] = 'joined_at';
        }

        if (null !== $modified) {
            $columns[$modified] = 'touched_at';
        }

        return [$users, new AccountStorage($users), $fields, new FieldMap(['User' => $columns])];
    }
}
