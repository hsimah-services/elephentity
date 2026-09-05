<?php

declare(strict_types=1);

namespace PheFr\Runtime\Tests\Storage;

use InvalidArgumentException;
use OutOfBoundsException;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Identity\PendingId;
use PheFr\Runtime\Storage\Comparison;
use PheFr\Runtime\Storage\Criteria;
use PheFr\Runtime\Storage\Cursor;
use PheFr\Runtime\Storage\Direction;
use PheFr\Runtime\Storage\Filter;
use PheFr\Runtime\Storage\Order;
use PheFr\Runtime\Storage\Page;
use PheFr\Runtime\Storage\Record;
use PheFr\Runtime\Storage\Write\Insert;
use PheFr\Runtime\Storage\Write\WriteBatch;
use PheFr\Runtime\Storage\Write\WriteResult;
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
