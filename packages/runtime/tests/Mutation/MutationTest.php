<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Mutation;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Mutation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Mutation::class)]
final class MutationTest extends TestCase
{
    public function testAnUntouchedEdgeIsUnchangedAndEmpty(): void
    {
        $mutation = new Mutation('PointsTransaction', new PendingId('PointsTransaction'));

        self::assertFalse($mutation->isEdgeChanged('tutorial'));
        self::assertSame([], $mutation->pendingEdge('tutorial'));
    }

    public function testAddingToAnEdgeMakesItPending(): void
    {
        $mutation = new Mutation('PointsTransaction', new PendingId('PointsTransaction'));
        $tutorial = EntityId::of(1);

        $mutation->edge('tutorial')->add($tutorial);

        self::assertTrue($mutation->isEdgeChanged('tutorial'));
        self::assertSame([$tutorial], $mutation->pendingEdge('tutorial'));
    }

    public function testSettingAnEdgeReplacesWhatIsPending(): void
    {
        $mutation = new Mutation('Post', EntityId::of(1));
        $a = EntityId::of(2);
        $b = EntityId::of(3);

        $mutation->edge('tags')->set([$a, $b]);

        self::assertTrue($mutation->isEdgeChanged('tags'));
        self::assertSame([$a, $b], $mutation->pendingEdge('tags'));
    }

    public function testRemovingFromAnEdgeChangesItWithoutAddingAnything(): void
    {
        // Nothing to fall back to — there is no original edge state here — so a
        // mutation that only removes a target reports nothing pending, which is
        // exactly what MutationBuffer::edge() already exposes.
        $mutation = new Mutation('Post', EntityId::of(1));

        $mutation->edge('tags')->remove(EntityId::of(2));

        self::assertTrue($mutation->isEdgeChanged('tags'));
        self::assertSame([], $mutation->pendingEdge('tags'));
    }

    public function testIdFallsBackToTheTargetUntilResolved(): void
    {
        $pending = new PendingId('Post');
        $mutation = new Mutation('Post', $pending);

        self::assertSame($pending, $mutation->id());

        $resolved = EntityId::of(42);
        $mutation->resolveId($resolved);

        self::assertTrue($resolved->equals($mutation->id()));
        // target() stays the original PendingId — isCreate() depends on that.
        self::assertSame($pending, $mutation->target());
        self::assertTrue($mutation->isCreate());
    }

    public function testIdIsTheTargetItselfWhenThereIsNothingToResolve(): void
    {
        $id = EntityId::of(7);
        $mutation = new Mutation('Post', $id);

        self::assertSame($id, $mutation->id());
    }

    public function testExactlyOneOfSeveralEdgesCanBeCheckedFromTheContext(): void
    {
        // The motivating case: a preCommit trigger enforcing "exactly one of
        // tutorial/quiz/commodity is set" has no field to attach verify: true to, and
        // needed pendingEdge() to read edge state at all.
        $mutation = new Mutation('PointsTransaction', new PendingId('PointsTransaction'));
        $mutation->edge('quiz')->add(EntityId::of(9));

        $edges = ['tutorial', 'quiz', 'commodity'];
        $set = array_filter($edges, static fn (string $edge): bool => [] !== $mutation->pendingEdge($edge));

        self::assertSame(['quiz'], array_values($set));
    }
}
