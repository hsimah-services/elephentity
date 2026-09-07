<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\UnitOfWork\UniquenessCheck;
use Eleph\Runtime\UnitOfWork\ValueEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UniquenessCheck::class)]
final class UniquenessCheckTest extends TestCase
{
    public function testATakenValueIsAViolationWithAFieldPath(): void
    {
        // Rather than a driver exception carrying an index name and the statement that
        // produced it, which is what `onDelete: restrict` already knows better than to
        // do.
        $storage = new FakeStorage();
        $storage->countResult = 1;

        $mutation = new Mutation('Item', new PendingId('Item'));
        $mutation->set('barcode', '013000006057');

        $violations = $this->check($storage)->check($mutation);

        self::assertCount(1, $violations);
        self::assertSame('Item.barcode', $violations[0]->path());
        self::assertSame('field.unique', $violations[0]->violation->code);
    }

    public function testAFreeValuePasses(): void
    {
        $storage = new FakeStorage();
        $storage->countResult = 0;

        $mutation = new Mutation('Item', new PendingId('Item'));
        $mutation->set('barcode', '013000006057');

        self::assertSame([], $this->check($storage)->check($mutation));
    }

    public function testARowDoesNotCollideWithItself(): void
    {
        // On update the row already holds the value it is being asked to keep.
        $storage = new FakeStorage();

        $mutation = new Mutation('Item', EntityId::of(4));
        $mutation->set('barcode', '013000006057');

        $this->check($storage)->check($mutation);

        $filters = $storage->counted[0]->filters;

        self::assertCount(2, $filters);
        self::assertSame('id', $filters[1]->field);
        self::assertSame(Comparison::NotEquals, $filters[1]->comparison);
        self::assertSame(4, $filters[1]->value);
    }

    public function testNullIsNeverTaken(): void
    {
        // NULL does not collide with NULL in SQL, and a nullable unique column is how
        // "at most one, if any" is spelled. Checking it would forbid the second row
        // with no barcode.
        $storage = new FakeStorage();
        $storage->countResult = 1;

        $mutation = new Mutation('Item', new PendingId('Item'));
        $mutation->set('barcode', null);

        self::assertSame([], $this->check($storage)->check($mutation));
        self::assertSame([], $storage->counted);
    }

    public function testAFieldTheMutationDoesNotTouchIsNotChecked(): void
    {
        $storage = new FakeStorage();
        $storage->countResult = 1;

        $mutation = new Mutation('Item', EntityId::of(4));
        $mutation->set('name', 'Beans');

        self::assertSame([], $this->check($storage)->check($mutation));
        self::assertSame([], $storage->counted);
    }

    private function check(FakeStorage $storage): UniquenessCheck
    {
        return new UniquenessCheck(
            $storage,
            new ValueEncoder([], new StubProcessors()),
            ['Item' => ['barcode']],
        );
    }
}
