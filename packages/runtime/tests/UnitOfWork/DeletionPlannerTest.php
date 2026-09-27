<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Storage\DeletionPolicy;
use Eleph\Runtime\Storage\DeletionRule;
use Eleph\Runtime\Storage\DeletionRules;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\WriteOperation;
use Eleph\Runtime\UnitOfWork\DeletionPlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeletionPlanner::class)]
final class DeletionPlannerTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, bool, bool}> */
    public static function directions(): iterable
    {
        yield 'to-one target' => ['Item', 'Inventory', 'Inventory', false, true];
        yield 'to-many owner' => ['Post', 'Comment', 'Post', false, false];
        yield 'join owner' => ['Post', 'Tag', 'Post', true, false];
        yield 'join target' => ['Tag', 'Post', 'Post', true, true];
    }

    #[DataProvider('directions')]
    public function testRestrictCountsTheDependentSide(string $deleted, string $dependent, string $declaredBy, bool $join, bool $reversed): void
    {
        $storage = new FakeStorage();
        $this->plan(
            [$deleted => [new DeletionRule($dependent, 'edge', $declaredBy, DeletionPolicy::Restrict, $join)]],
            new Deletion($deleted, EntityId::of(5)),
            $storage,
        );

        self::assertCount(1, $storage->counted);
        $criteria = $storage->counted[0];
        self::assertSame($dependent, $criteria->entity);
        self::assertSame($reversed, $criteria->links[0]->reversed);
        self::assertSame($declaredBy, $criteria->links[0]->entity);
        self::assertEquals([EntityId::of(5)], $criteria->links[0]->from);
    }

    #[DataProvider('directions')]
    public function testCascadeReadsTheDependentSide(string $deleted, string $dependent, string $declaredBy, bool $join, bool $reversed): void
    {
        $storage = new FakeStorage();
        $storage->records[$dependent] = [new Record($dependent, EntityId::of(20), [])];
        $operations = $this->plan(
            [$deleted => [new DeletionRule($dependent, 'edge', $declaredBy, DeletionPolicy::Cascade, $join)]],
            new Deletion($deleted, EntityId::of(5)),
            $storage,
        );

        self::assertCount(1, $storage->queried);
        self::assertSame($reversed, $storage->queried[0]->links[0]->reversed);
        self::assertSame($dependent, $storage->queried[0]->entity);
        $deletes = array_values(array_filter($operations, static fn ($operation) => $operation instanceof Delete));
        self::assertEquals([new Delete($dependent, EntityId::of(20)), new Delete($deleted, EntityId::of(5))], $deletes);
        if ($join) {
            self::assertEquals(new Unlink($declaredBy, 'edge', EntityId::of($reversed ? 20 : 5), $reversed ? EntityId::of(5) : null), $operations[0]);
        }
    }

    public function testNullifyFromTheTargetUnlinksEachDeclaringRow(): void
    {
        $storage = new FakeStorage();
        $storage->records['Inventory'] = [
            new Record('Inventory', EntityId::of(20), []),
            new Record('Inventory', EntityId::of(21), []),
        ];
        $operations = $this->plan(
            ['Item' => [new DeletionRule('Inventory', 'item', 'Inventory', DeletionPolicy::Nullify)]],
            new Deletion('Item', EntityId::of(5)),
            $storage,
        );

        self::assertEquals([
            new Unlink('Inventory', 'item', EntityId::of(20), EntityId::of(5)),
            new Unlink('Inventory', 'item', EntityId::of(21), EntityId::of(5)),
            new Delete('Item', EntityId::of(5)),
        ], $operations);
    }

    public function testAnEntityNothingDependsOnIsJustDeleted(): void
    {
        $operations = $this->plan([], new Deletion('Tag', EntityId::of(1)));

        self::assertCount(1, $operations);
        self::assertInstanceOf(Delete::class, $operations[0]);
        self::assertSame('Tag', $operations[0]->entity());
    }

    public function testCascadeDeletesDependentsFirst(): void
    {
        // Ordering is the point: a foreign key must not dangle even briefly.
        $storage = new FakeStorage();
        $storage->records = ['Comment' => [
            new Record('Comment', EntityId::of(10), []),
            new Record('Comment', EntityId::of(11), []),
        ]];

        $operations = $this->plan(
            ['Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Cascade)]],
            new Deletion('Post', EntityId::of(1)),
            $storage,
        );

        self::assertSame(
            ['Comment', 'Comment', 'Post'],
            array_map(static fn ($o) => $o->entity(), $operations),
        );
    }

    public function testCascadeRecursesThroughTheDependentsOwnEdges(): void
    {
        $storage = new FakeStorage();
        $storage->records = [
            'Comment' => [new Record('Comment', EntityId::of(10), [])],
            'Flag' => [new Record('Flag', EntityId::of(20), [])],
        ];

        $operations = $this->plan(
            [
                'Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Cascade)],
                'Comment' => [new DeletionRule('Flag', 'flags', 'Comment', DeletionPolicy::Cascade)],
            ],
            new Deletion('Post', EntityId::of(1)),
            $storage,
        );

        self::assertSame(
            ['Flag', 'Comment', 'Post'],
            array_map(static fn ($o) => $o->entity(), $operations),
        );
    }

    public function testRestrictRefusesWhileAnythingStillDependsOnIt(): void
    {
        $storage = new FakeStorage();
        $storage->countResult = 3;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('3 Comment still depend on it through "comments"');

        $this->plan(
            ['Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Restrict)]],
            new Deletion('Post', EntityId::of(1)),
            $storage,
        );
    }

    public function testRestrictAllowsTheDeleteWhenNothingDependsOnIt(): void
    {
        $storage = new FakeStorage();
        $storage->countResult = 0;

        $operations = $this->plan(
            ['Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Restrict)]],
            new Deletion('Post', EntityId::of(1)),
            $storage,
        );

        self::assertCount(1, $operations);
    }

    public function testNullifyKeepsDependentsAndClearsTheirReference(): void
    {
        $operations = $this->plan(
            ['Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Nullify)]],
            new Deletion('Post', EntityId::of(1)),
        );

        self::assertInstanceOf(Unlink::class, $operations[0]);
        self::assertSame('comments', $operations[0]->edge);
        self::assertInstanceOf(Delete::class, $operations[1]);
    }

    public function testJoinRowsGoWhateverThePolicySays(): void
    {
        // A link to a row that will not exist is not a policy choice. Only whether the
        // far side follows is up to the spec.
        $operations = $this->plan(
            ['Post' => [new DeletionRule('Tag', 'tags', 'Post', DeletionPolicy::Nullify, viaJoinTable: true)]],
            new Deletion('Post', EntityId::of(1)),
        );

        self::assertInstanceOf(Unlink::class, $operations[0]);
        self::assertCount(2, $operations, 'the links and the post, but not the tags');
    }

    public function testCascadeOnAJoinTableTakesTheFarSideToo(): void
    {
        // Rarely wanted for shared vocabulary, which is exactly why it must be asked
        // for rather than assumed.
        $storage = new FakeStorage();
        $storage->records = ['Tag' => [new Record('Tag', EntityId::of(7), [])]];

        $operations = $this->plan(
            ['Post' => [new DeletionRule('Tag', 'tags', 'Post', DeletionPolicy::Cascade, viaJoinTable: true)]],
            new Deletion('Post', EntityId::of(1)),
            $storage,
        );

        self::assertSame(
            ['Post', 'Tag', 'Post'],
            array_map(static fn ($o) => $o->entity(), $operations),
            'unlink the join rows, delete the tag, delete the post',
        );
    }

    public function testAPolicyLoopIsRefusedRatherThanFollowed(): void
    {
        // Post cascades to Comment, Comment cascades back to Post. Without a visited
        // set this runs until the stack gives out.
        $storage = new FakeStorage();
        $storage->records = [
            'Comment' => [new Record('Comment', EntityId::of(10), [])],
            'Post' => [new Record('Post', EntityId::of(1), [])],
        ];

        $operations = $this->plan(
            [
                'Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Cascade)],
                'Comment' => [new DeletionRule('Post', 'post', 'Comment', DeletionPolicy::Cascade)],
            ],
            new Deletion('Post', EntityId::of(1)),
            $storage,
        );

        self::assertSame(['Comment', 'Post'], array_map(static fn ($o) => $o->entity(), $operations));
    }

    /**
     * @param array<string, list<DeletionRule>> $rules
     *
     * @return list<WriteOperation>
     */
    private function plan(array $rules, Deletion $deletion, ?FakeStorage $storage = null): array
    {
        return (new DeletionPlanner($storage ?? new FakeStorage(), new class ($rules) implements DeletionRules {
            /** @param array<string, list<DeletionRule>> $rules */
            public function __construct(private readonly array $rules)
            {
            }

            public function for(string $entity): array
            {
                return $this->rules[$entity] ?? [];
            }
        }))->plan([$deletion]);
    }
}
