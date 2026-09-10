<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use DateTimeImmutable;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Mutation\Managed;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Storage\DeletionPolicy;
use Eleph\Runtime\Storage\DeletionRule;
use Eleph\Runtime\Storage\DeletionRules;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Trigger\TriggerPhase;
use Eleph\Runtime\Type\WriteProcessor;
use Eleph\Runtime\UnitOfWork\DeletionPlanner;
use Eleph\Runtime\UnitOfWork\DependencySorter;
use Eleph\Runtime\UnitOfWork\ManagedFields;
use Eleph\Runtime\UnitOfWork\TriggerDispatcher;
use Eleph\Runtime\UnitOfWork\UnitOfWork;
use Eleph\Runtime\UnitOfWork\ValueEncoder;
use Eleph\Runtime\UnitOfWork\VerificationPipeline;
use Eleph\Runtime\Verification\CommitRejected;
use Eleph\Runtime\Verification\Verification;
use Eleph\Runtime\Verification\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UnitOfWork::class)]
#[CoversClass(VerificationPipeline::class)]
#[CoversClass(TriggerDispatcher::class)]
final class UnitOfWorkTest extends TestCase
{
    public function testAnEmptyCommitTouchesNothing(): void
    {
        $storage = new FakeStorage();

        $this->unitOfWork($storage)->commit();

        self::assertSame([], $storage->log);
    }

    public function testAMutationThatSetsNothingIsNotACommit(): void
    {
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage);

        $work->register(new Mutation('Post', EntityId::of(1)));

        self::assertTrue($work->isEmpty());

        $work->commit();

        self::assertSame([], $storage->log);
    }

    public function testARejectedCommitWritesNothingAndNamesEveryProblem(): void
    {
        // Verification runs over every mutation before anything is written, so a
        // rejection leaves no partial state and costs one round trip, not five.
        $storage = new FakeStorage();

        $work = $this->unitOfWork($storage, verifiers: [
            'Post' => new StubVerifiers([
                'title' => Verification::failed(new Violation('post.title.empty', 'Must not be empty.')),
                'slug' => Verification::failed(new Violation('post.slug.taken', 'Already taken.')),
            ]),
        ]);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', '');
        $mutation->set('slug', 'hello');

        $work->register($mutation);

        try {
            $work->commit();
            self::fail('the commit should have been rejected');
        } catch (CommitRejected $rejected) {
            self::assertCount(2, $rejected->violations);
            self::assertSame(['Post.title', 'Post.slug'], $rejected->paths());
        }

        self::assertSame([], $storage->log, 'nothing may be written when verification fails');
    }

    public function testBothVerificationTiersRunEvenWhenTheFirstFails(): void
    {
        // The field verifier and the type processor both report, so a caller does not
        // fix one rule only to discover the next.
        $storage = new FakeStorage();

        $work = $this->unitOfWork(
            $storage,
            verifiers: [
                'Post' => new StubVerifiers([
                    'price' => Verification::failed(new Violation('post.price.tooHigh', 'Too high.')),
                ]),
            ],
            fieldTypes: ['Post.price' => 'Money'],
            processors: new StubProcessors([
                'Money' => $this->processor(
                    Verification::failed(new Violation('money.negative', 'Must not be negative.')),
                ),
            ]),
        );

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('price', -1);
        $work->register($mutation);

        try {
            $work->commit();
            self::fail('the commit should have been rejected');
        } catch (CommitRejected $rejected) {
            $codes = array_map(
                static fn ($violation) => $violation->violation->code,
                $rejected->violations,
            );

            self::assertSame(['post.price.tooHigh', 'money.negative'], $codes);
        }
    }

    public function testNullSkipsVerificationEntirely(): void
    {
        // A nullable field holding null short-circuits, so no processor needs to open
        // with the same null check.
        $storage = new FakeStorage();

        $work = $this->unitOfWork($storage, verifiers: [
            'Post' => new StubVerifiers([
                'price' => Verification::failed(new Violation('never', 'Should not run.')),
            ]),
        ]);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('price', null);
        $work->register($mutation);

        $work->commit();

        self::assertContains('commit', $storage->log);
    }

    public function testParentsAreWrittenBeforeTheChildrenThatReferenceThem(): void
    {
        // Server-generated ids mean the Post must exist before a Comment can point at
        // it, so the batch arrives sorted and the adaptor never reasons about order.
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage);

        $postId = new PendingId('Post');
        $commentId = new PendingId('Comment');

        $comment = new Mutation('Comment', $commentId);
        $comment->set('body', 'Hi');

        $post = new Mutation('Post', $postId);
        $post->set('title', 'Hello');
        $post->edge('comments')->add($commentId);

        // Registered child-first on purpose; the sorter must fix it.
        $work->register($comment);
        $work->register($post);

        $work->commit();

        $rows = $storage->batches[0]->operations;

        self::assertInstanceOf(Insert::class, $rows[0]);
        self::assertSame('Comment', $rows[0]->entity());
        self::assertSame('Post', $rows[1]->entity());
    }

    public function testLinksAreWrittenAfterRowsWithRealIds(): void
    {
        $storage = new FakeStorage();
        $storage->nextId = 10;
        $work = $this->unitOfWork($storage);

        $postId = new PendingId('Post');
        $commentId = new PendingId('Comment');

        $comment = new Mutation('Comment', $commentId);
        $comment->set('body', 'Hi');

        $post = new Mutation('Post', $postId);
        $post->set('title', 'Hello');
        $post->edge('comments')->add($commentId);

        $work->register($comment);
        $work->register($post);
        $work->commit();

        self::assertCount(2, $storage->batches, 'rows first, then links');

        $link = $storage->batches[1]->operations[0];

        self::assertInstanceOf(Link::class, $link);
        // Both ends resolved from pending to real before the link was written.
        self::assertTrue($link->from->isPersisted());
        self::assertTrue($link->to->isPersisted());
    }

    public function testLinkingToSomethingNoOneCreatesIsRefused(): void
    {
        $work = $this->unitOfWork(new FakeStorage());

        $post = new Mutation('Post', new PendingId('Post'));
        $post->set('title', 'Hello');
        $post->edge('comments')->add(new PendingId('Comment'));

        $work->register($post);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('which no mutation in this commit creates');

        $work->commit();
    }

    public function testMutuallyDependentWritesAreRefusedRatherThanLooping(): void
    {
        $work = $this->unitOfWork(new FakeStorage());

        $left = new PendingId('Post');
        $right = new PendingId('Post');

        $first = new Mutation('Post', $left);
        $first->set('title', 'a');
        $first->edge('related')->add($right);

        $second = new Mutation('Post', $right);
        $second->set('title', 'b');
        $second->edge('related')->add($left);

        $work->register($first);
        $work->register($second);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('depend on each other');

        $work->commit();
    }

    public function testACreateMissingARequiredFieldIsRejectedBeforeAnySql(): void
    {
        // Otherwise the omission reaches the database, and what happens next depends
        // on the installation: strict MySQL errors, WordPress's default session
        // quietly stores a zero date.
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, required: ['Post' => ['title', 'body']]);

        $mutation = new Mutation('Post', new PendingId('Post'));
        $mutation->set('title', 'Hello');
        $mutation->set('body', null);

        $work->register($mutation);

        try {
            $work->commit();
            self::fail('the commit should have been rejected');
        } catch (CommitRejected $rejected) {
            // A key that is present but null is missing: the caller said the field was
            // there and it holds nothing.
            self::assertSame(['Post.body'], $rejected->paths());
        }

        self::assertSame([], $storage->log);
    }

    public function testAnUpdateStaysPartialWhateverIsRequired(): void
    {
        // `required` describes creating a row. Demanding it on update would mean no
        // update could ever name only the field it meant to change.
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, required: ['Post' => ['title', 'body']]);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', 'Hello');
        $work->register($mutation);

        $work->commit();

        self::assertSame(['begin', 'update Post', 'commit'], $storage->log);
    }

    public function testACreateMissingARequiredEdgeIsRejectedBeforeAnySql(): void
    {
        // A ledger row with no owning user should not be constructible — the same
        // rule as a required field, for a to-one edge instead.
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, requiredEdges: ['PointsTransaction' => ['user']]);

        $mutation = new Mutation('PointsTransaction', new PendingId('PointsTransaction'));
        $mutation->set('value', 10);

        $work->register($mutation);

        try {
            $work->commit();
            self::fail('the commit should have been rejected');
        } catch (CommitRejected $rejected) {
            self::assertSame(['PointsTransaction.user'], $rejected->paths());
        }

        self::assertSame([], $storage->log);
    }

    public function testARequiredEdgeThatIsAttachedPasses(): void
    {
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, requiredEdges: ['PointsTransaction' => ['user']]);

        $mutation = new Mutation('PointsTransaction', new PendingId('PointsTransaction'));
        $mutation->set('value', 10);
        $mutation->edge('user')->add(EntityId::of(1));

        $work->register($mutation);
        $work->commit();

        self::assertContains('commit', $storage->log);
    }

    public function testAnUpdateStaysPartialWhateverEdgeIsRequired(): void
    {
        // required describes creating a row, the same as a required field — an update
        // that never touches the edge is not thereby forced to attach it.
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, requiredEdges: ['PointsTransaction' => ['user']]);

        $mutation = new Mutation('PointsTransaction', EntityId::of(1));
        $mutation->set('value', 20);

        $work->register($mutation);
        $work->commit();

        self::assertSame(['begin', 'update PointsTransaction', 'commit'], $storage->log);
    }

    public function testAManagedFieldIsStampedBeforeAnythingLooksAtIt(): void
    {
        // Stamped first, so the required check sees a value that is really there. The
        // two together are what let a Timestamps pattern stop being API input.
        $storage = new FakeStorage();
        $work = $this->unitOfWork(
            $storage,
            required: ['Post' => ['createdAt']],
            managed: new ManagedFields([
                'Post.createdAt' => Managed::Created,
                'Post.updatedAt' => Managed::Modified,
            ]),
        );

        $mutation = new Mutation('Post', new PendingId('Post'));
        $mutation->set('title', 'Hello');
        $work->register($mutation);

        $work->commit();

        $changes = $mutation->changes();

        self::assertInstanceOf(DateTimeImmutable::class, $changes['createdAt']);
        self::assertInstanceOf(DateTimeImmutable::class, $changes['updatedAt']);
        // One instant for the whole commit, so "never modified" is testable.
        self::assertEquals($changes['createdAt'], $changes['updatedAt']);
    }

    public function testAnUpdateStampsWhatChangedAndNotWhenItWasCreated(): void
    {
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, managed: new ManagedFields([
            'Post.createdAt' => Managed::Created,
            'Post.updatedAt' => Managed::Modified,
        ]));

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', 'Hello');
        $work->register($mutation);

        $work->commit();

        self::assertArrayNotHasKey('createdAt', $mutation->changes());
        self::assertArrayHasKey('updatedAt', $mutation->changes());
    }

    public function testAnUntouchedEntityIsNotStamped(): void
    {
        // A stamp on a mutation nobody wrote to would turn every read into a write.
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage, managed: new ManagedFields([
            'Post.updatedAt' => Managed::Modified,
        ]));

        $mutation = new Mutation('Post', EntityId::of(1));
        $work->register($mutation);
        $work->commit();

        self::assertSame([], $mutation->changes());
        self::assertSame([], $storage->log);
    }

    public function testPreCommitTriggersRunInsideTheTransaction(): void
    {
        $storage = new FakeStorage();
        $triggers = new RecordingTriggers();

        $work = $this->unitOfWork($storage, triggers: ['Post' => $triggers]);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', 'Hello');
        $work->register($mutation);
        $work->commit();

        self::assertSame(
            ['preCommit:update:Post:1', 'postCommit:update:Post:1'],
            $triggers->calls,
        );

        // preCommit fired between the write and the commit; postCommit after it.
        self::assertSame(['begin', 'update Post', 'commit'], $storage->log);
    }

    public function testATriggerOnACreateSeesTheRealIdInBothPhases(): void
    {
        // The row does not exist until the insert flushes, but both trigger phases run
        // after that — so neither should ever see the PendingId placeholder the
        // mutation started with.
        $storage = new FakeStorage();
        $storage->nextId = 42;
        $triggers = new RecordingTriggers();

        $work = $this->unitOfWork($storage, triggers: ['Post' => $triggers]);

        $mutation = new Mutation('Post', new PendingId('Post'));
        $mutation->set('title', 'Hello');
        $work->register($mutation);
        $work->commit();

        self::assertSame(
            ['preCommit:create:Post:42', 'postCommit:create:Post:42'],
            $triggers->calls,
        );
    }

    public function testAPreCommitTriggerThrowingRollsBackTheWholeCommit(): void
    {
        $storage = new FakeStorage();
        $triggers = new RecordingTriggers();
        $triggers->failOn(TriggerPhase::PreCommit, static function (): void {
            throw new RuntimeException('an invariant failed');
        });

        $work = $this->unitOfWork($storage, triggers: ['Post' => $triggers]);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', 'Hello');
        $work->register($mutation);

        try {
            $work->commit();
            self::fail('the trigger should have aborted the commit');
        } catch (RuntimeException $exception) {
            self::assertSame('an invariant failed', $exception->getMessage());
        }

        self::assertContains('rollback', $storage->log);
        self::assertNotContains('commit', $storage->log);
    }

    public function testAPostCommitTriggerThrowingDoesNotUndoTheCommit(): void
    {
        // There is nothing left to roll back, so the failure is logged and the data
        // stays written.
        $storage = new FakeStorage();
        $triggers = new RecordingTriggers();
        $triggers->failOn(TriggerPhase::PostCommit, static function (): void {
            throw new RuntimeException('the search index is down');
        });

        $work = $this->unitOfWork($storage, triggers: ['Post' => $triggers]);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', 'Hello');
        $work->register($mutation);

        $work->commit();

        self::assertContains('commit', $storage->log);
        self::assertNotContains('rollback', $storage->log);
    }

    public function testEveryPlannedRemovalIsAnnouncedBeforeItHappens(): void
    {
        // An audit trail or an external projection needs to read the row it is being
        // told about, and after the DELETE there is nothing to read.
        $storage = new FakeStorage();
        $triggers = new RecordingTriggers();

        $work = $this->unitOfWork(
            $storage,
            triggers: ['Tag' => $triggers],
            planner: $this->planner($storage, []),
        );

        $work->delete(new Deletion('Tag', EntityId::of(7)));
        $work->commit();

        self::assertSame(['preCommit:delete:Tag:7', 'postCommit:delete:Tag:7'], $triggers->calls);
        self::assertSame(['begin', 'delete Tag', 'commit'], $storage->log);
    }

    public function testACascadedRowIsAnnouncedToo(): void
    {
        // Hearing only about the row someone asked to delete would leave a projection
        // silently incomplete, which is the failure mode that is hardest to notice.
        $storage = new FakeStorage();
        $storage->records = ['Comment' => [new Record('Comment', EntityId::of(10), [])]];

        $posts = new RecordingTriggers();
        $comments = new RecordingTriggers();

        $work = $this->unitOfWork(
            $storage,
            triggers: ['Post' => $posts, 'Comment' => $comments],
            planner: $this->planner($storage, [
                'Post' => [new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Cascade)],
            ]),
        );

        $work->delete(new Deletion('Post', EntityId::of(1)));
        $work->commit();

        self::assertSame(['preCommit:delete:Comment:10', 'postCommit:delete:Comment:10'], $comments->calls);
        self::assertSame(['preCommit:delete:Post:1', 'postCommit:delete:Post:1'], $posts->calls);
    }

    public function testADeleteTriggerThrowingRollsBackTheDeletion(): void
    {
        $storage = new FakeStorage();
        $triggers = new RecordingTriggers();
        $triggers->failOn(TriggerPhase::PreCommit, static function (): void {
            throw new RuntimeException('that tag is still referenced elsewhere');
        });

        $work = $this->unitOfWork(
            $storage,
            triggers: ['Tag' => $triggers],
            planner: $this->planner($storage, []),
        );

        $work->delete(new Deletion('Tag', EntityId::of(7)));

        try {
            $work->commit();
            self::fail('the trigger should have aborted the commit');
        } catch (RuntimeException $exception) {
            self::assertSame('that tag is still referenced elsewhere', $exception->getMessage());
        }

        self::assertContains('rollback', $storage->log);
        self::assertNotContains('delete Tag', $storage->log);
    }

    public function testCommittingClearsTheUnitOfWork(): void
    {
        $storage = new FakeStorage();
        $work = $this->unitOfWork($storage);

        $mutation = new Mutation('Post', EntityId::of(1));
        $mutation->set('title', 'Hello');
        $work->register($mutation);
        $work->commit();
        $work->commit();

        self::assertCount(1, $storage->batches);
    }

    /**
     * @param array<string, StubVerifiers>     $verifiers
     * @param array<string, string>            $fieldTypes
     * @param array<string, RecordingTriggers> $triggers
     * @param array<string, list<string>>      $required
     * @param array<string, list<string>>      $requiredEdges
     */
    private function unitOfWork(
        FakeStorage $storage,
        array $verifiers = [],
        array $fieldTypes = [],
        ?StubProcessors $processors = null,
        array $triggers = [],
        ?DeletionPlanner $planner = null,
        ?ManagedFields $managed = null,
        array $required = [],
        array $requiredEdges = [],
    ): UnitOfWork {
        $processors ??= new StubProcessors();

        return new UnitOfWork(
            $storage,
            new VerificationPipeline($verifiers, $fieldTypes, $processors, $required, $requiredEdges),
            new ValueEncoder($fieldTypes, $processors),
            new TriggerDispatcher($triggers),
            new DependencySorter(),
            planner: $planner,
            managed: $managed ?? new ManagedFields(),
        );
    }

    /**
     * @param array<string, list<DeletionRule>> $rules
     */
    private function planner(FakeStorage $storage, array $rules): DeletionPlanner
    {
        return new DeletionPlanner($storage, new class ($rules) implements DeletionRules {
            /** @param array<string, list<DeletionRule>> $rules */
            public function __construct(private readonly array $rules)
            {
            }

            public function for(string $entity): array
            {
                return $this->rules[$entity] ?? [];
            }
        });
    }

    /**
     * @return WriteProcessor<scalar, mixed>
     */
    private function processor(Verification $verification): WriteProcessor
    {
        return new class ($verification) implements WriteProcessor {
            public function __construct(private readonly Verification $verification)
            {
            }

            public function verify(mixed $value, \Eleph\Runtime\Mutation\MutationContext $context): Verification
            {
                return $this->verification;
            }

            public function write(mixed $value): int
            {
                assert(is_int($value));

                return $value;
            }
        };
    }
}
