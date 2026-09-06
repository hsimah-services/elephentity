<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Storage;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Direction;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Order;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Storage\Write\WriteResult;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Criteria::class)]
#[CoversClass(Cursor::class)]
#[CoversClass(Filter::class)]
#[CoversClass(Page::class)]
#[CoversClass(Record::class)]
#[CoversClass(WriteBatch::class)]
#[CoversClass(WriteResult::class)]
final class StorageContractTest extends TestCase
{
    public function testCriteriaBuildersDoNotMutateTheOriginal(): void
    {
        $base = new Criteria('Post');

        $narrowed = $base
            ->where(new Filter('status', Comparison::Equals, 'published'))
            ->orderBy(new Order('createdAt', Direction::Descending))
            ->take(20);

        self::assertSame([], $base->filters);
        self::assertNull($base->limit);
        self::assertCount(1, $narrowed->filters);
        self::assertSame(20, $narrowed->limit);
        self::assertSame(Direction::Descending, $narrowed->order[0]->direction);
    }

    public function testAValuelessComparisonRejectsAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Filter('deletedAt', Comparison::IsNull, 'something');
    }

    public function testACursorCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cursor::of('');
    }

    public function testAPageWithoutACursorHasNoMore(): void
    {
        $page = Page::empty();

        self::assertTrue($page->isEmpty());
        self::assertFalse($page->hasMore());

        $more = new Page(['a'], Cursor::of('abc'));

        self::assertTrue($more->hasMore());
        self::assertSame('abc', (string) $more->next);
    }

    public function testARecordReadsMissingFieldsAsNull(): void
    {
        $record = new Record('Post', EntityId::of(1), ['title' => 'Hello']);

        self::assertSame('Hello', $record->value('title'));
        self::assertNull($record->value('subtitle'));
        self::assertFalse($record->has('subtitle'));
    }

    public function testAWriteResultResolvesPendingIdsToRealOnes(): void
    {
        $pending = new PendingId('Post');
        $result = new WriteResult();

        self::assertFalse($result->wasAssigned($pending));

        $result->assign($pending, EntityId::of(42));

        self::assertTrue($result->wasAssigned($pending));
        self::assertTrue(EntityId::of(42)->equals($result->idFor($pending)));
    }

    public function testAskingForAnUnassignedIdFails(): void
    {
        $this->expectException(OutOfBoundsException::class);

        (new WriteResult())->idFor(new PendingId('Post'));
    }

    public function testABatchPreservesTheOrderItWasGiven(): void
    {
        // Order is the unit of work's responsibility: with server-generated ids a Post
        // must be inserted before the Comments that reference it.
        $post = new Insert('Post', new PendingId('Post'), ['title' => 'Hello']);
        $comment = new Insert('Comment', new PendingId('Comment'), ['body' => 'Hi']);

        $batch = new WriteBatch($post, $comment);

        self::assertCount(2, $batch);
        self::assertSame('Post', $batch->operations[0]->entity());
        self::assertSame('Comment', $batch->operations[1]->entity());
        self::assertFalse($batch->isEmpty());
    }
}
