<?php

declare(strict_types=1);

namespace Eleph\Memory\Tests;

use Closure;
use Eleph\Memory\MemoryAdaptor;
use Eleph\Runtime\Catalogue\EntityCatalogue;
use Eleph\Runtime\Gateway\Runtime;
use Eleph\Runtime\Gateway\UnitOfWorkFactory;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\ActionCall;
use Eleph\Runtime\Mutation\EntitySideEffects;
use Eleph\Runtime\Mutation\MutableMutationContext;
use Eleph\Runtime\Mutation\MutationBuffer;
use Eleph\Runtime\Policy\AnonymousViewerProvider;
use Eleph\Runtime\Policy\EntityReadPolicies;
use Eleph\Runtime\Policy\EntityWritePolicies;
use Eleph\Runtime\Policy\NoPolicies;
use Eleph\Runtime\Policy\PolicyDecision;
use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Policy\WriteContext;
use Eleph\Runtime\Policy\WriteGate;
use Eleph\Runtime\Query\EdgeLoader;
use Eleph\Runtime\Query\Hydrator;
use Eleph\Runtime\SideEffect\SideEffectEvent;
use Eleph\Runtime\SideEffect\SideEffectPhase;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Type\NullProcessorRegistry;
use Eleph\Runtime\Verification\CommitRejected;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MutationLifecycleTest extends TestCase
{
    public function testActionsAndSideEffectsShareOneCommitAndHiddenResultsAreSuccessful(): void
    {
        $storage = $this->storage();
        $policy = $this->createMock(EntityWritePolicies::class);
        $policy->method('isEmpty')->willReturn(false);
        $policy->expects(self::exactly(2))->method('decide')->willReturnCallback(
            static function (?object $original, WriteContext $context): PolicyDecision {
                self::assertNotNull($original);
                self::assertSame([], $context->mutation()?->changes());
                self::assertSame(['rename', 'rename'], array_map(static fn (ActionCall $call): string => $call->name, $context->actions()));
                self::assertSame('original', $context->mutation()->original('title'));
                return PolicyDecision::allow();
            },
        );
        $reads = $this->createMock(EntityReadPolicies::class);
        $reads->method('isEmpty')->willReturn(false);
        $reads->expects(self::once())->method('decide')->willReturnCallback(static function (object $entity): PolicyDecision {
            self::assertInstanceOf(LifecycleEntity::class, $entity);
            self::assertSame('second+pre', $entity->getTitle());
            return PolicyDecision::deny('hidden');
        });
        $post = [];
        $runtime = $this->runtime($storage, static function (SideEffectPhase $phase, MutableMutationContext $context) use ($storage, &$post): void {
            if (SideEffectPhase::PreCommit === $phase) {
                self::assertSame('original', $storage->get('Post', EntityId::of(1))?->value('title'));
                self::assertSame('second', $context->pending('title'));
                $context->set('title', 'second+pre');
                $context->edge('related')->add(EntityId::of(2));
                return;
            }
            $post[] = $storage->get('Post', EntityId::of(1))?->value('title');
            throw new RuntimeException('external service failed');
        }, $policy, $reads);

        $result = $runtime->runActions('Post', EntityId::of(1), [new ActionCall('rename', ['title' => 'first']), new ActionCall('rename', ['title' => 'second'])]);

        self::assertNull($result->entity);
        self::assertSame(['second+pre'], $post);
        self::assertSame('second+pre', $storage->get('Post', $result->id)?->value('title'));
    }

    public function testAnActionFailureDiscardsEarlierActions(): void
    {
        $storage = $this->storage();
        $runtime = $this->runtime($storage, static function (): void {
            self::fail('Side effects must not run.');
        });
        try {
            $runtime->runActions('Post', EntityId::of(1), [new ActionCall('rename', ['title' => 'first']), new ActionCall('fail')]);
            self::fail('Expected action failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('action failed', $exception->getMessage());
        }
        self::assertSame('original', $storage->get('Post', EntityId::of(1))?->value('title'));
    }

    public function testCreatePolicySeesEmptyBufferAndSideEffectsCanSupplyRequiredFields(): void
    {
        $storage = new MemoryAdaptor();
        $policy = $this->createMock(EntityWritePolicies::class);
        $policy->method('isEmpty')->willReturn(false);
        $policy->expects(self::once())->method('decide')->willReturnCallback(static function (?object $original, WriteContext $context): PolicyDecision {
            self::assertNull($original);
            self::assertSame([], $context->mutation()?->changes());
            self::assertSame(['title' => 'input'], $context->arguments());
            return PolicyDecision::allow();
        });
        $runtime = $this->runtime($storage, static function (SideEffectPhase $phase, MutableMutationContext $context): void {
            if (SideEffectPhase::PreCommit === $phase) {
                self::assertInstanceOf(PendingId::class, $context->id());
                $context->set('title', 'from side effect');
            } else {
                self::assertInstanceOf(EntityId::class, $context->id());
            }
        }, $policy);
        $result = $runtime->create('Post', ['title' => 'input']);
        self::assertInstanceOf(LifecycleEntity::class, $result->entity);
        self::assertSame('from side effect', $result->entity->getTitle());
    }

    public function testAnEmptyCreateCanBeCompletedByPreCommitSideEffects(): void
    {
        $storage = new MemoryAdaptor();
        $runtime = $this->runtime($storage, static function (SideEffectPhase $phase, MutableMutationContext $context): void {
            if (SideEffectPhase::PreCommit === $phase) {
                $context->set('title', 'default');
            }
        });
        $result = $runtime->create('Post', []);
        self::assertSame('default', $storage->get('Post', $result->id)?->value('title'));
    }

    public function testSideEffectChangesAreVerifiedBeforeStorage(): void
    {
        $storage = new MemoryAdaptor();
        $runtime = $this->runtime($storage, static function (SideEffectPhase $phase, MutableMutationContext $context): void {
            if (SideEffectPhase::PreCommit === $phase) {
                $context->set('title', null);
            }
        });
        try {
            $runtime->create('Post', ['title' => 'valid']);
            self::fail('Expected required field rejection.');
        } catch (CommitRejected) {
            self::assertNull($storage->get('Post', EntityId::of(1)));
        }
    }

    private function storage(): MemoryAdaptor
    {
        $storage = new MemoryAdaptor();
        $storage->write(new WriteBatch(new Insert('Post', new PendingId('Post'), ['title' => 'original'])));
        return $storage;
    }

    /** @param Closure(SideEffectPhase, MutableMutationContext): void $handler */
    private function runtime(MemoryAdaptor $storage, Closure $handler, ?EntityWritePolicies $writes = null, ?EntityReadPolicies $reads = null): Runtime
    {
        $catalogue = $this->createStub(EntityCatalogue::class);
        $catalogue->method('entities')->willReturn(['Post']);
        $catalogue->method('fieldNames')->willReturn(['title']);
        $catalogue->method('requiredFields')->willReturn(['title']);
        $catalogue->method('writePolicies')->willReturn($writes ?? new NoPolicies());
        $catalogue->method('readPolicies')->willReturn($reads ?? new NoPolicies());
        $catalogue->method('hydrator')->willReturn(new class () implements Hydrator {
            public function hydrate(Record $record, EdgeLoader $edges): object
            {
                return new LifecycleEntity((string) $record->value('title'));
            }
        });
        $catalogue->method('apply')->willReturnCallback(static function (string $entity, MutationBuffer $buffer, array $input): void {
            foreach ($input as $field => $value) {
                $buffer->set($field, $value);
            }
        });
        $catalogue->method('decodeActionArguments')->willReturnCallback(static fn (string $entity, string $action, array $args): array => $args);
        $catalogue->method('mutatorFor')->willReturnCallback(static fn (string $entity, MutationBuffer $buffer): object => new class ($buffer) {
            public function __construct(private MutationBuffer $buffer)
            {
            }
            public function rename(string $title): void
            {
                $this->buffer->set('title', $title);
            }
            public function fail(): void
            {
                throw new RuntimeException('action failed');
            }
        });
        $catalogue->method('sideEffects')->willReturn(new class ($handler) implements EntitySideEffects {
            /** @param Closure(SideEffectPhase, MutableMutationContext): void $handler */
            public function __construct(private Closure $handler)
            {
            }
            public function handlers(SideEffectPhase $phase, SideEffectEvent $event, MutableMutationContext $context): iterable
            {
                yield fn () => ($this->handler)($phase, $context);
            }
        });
        $viewers = new AnonymousViewerProvider();
        return new Runtime($storage, $catalogue, new UnitOfWorkFactory($storage, $catalogue, new NullProcessorRegistry()), new ReadGate($catalogue, $viewers), new WriteGate($catalogue, $viewers));
    }
}

final readonly class LifecycleEntity
{
    public function __construct(private string $title)
    {
    }
    public function getTitle(): string
    {
        return $this->title;
    }
}
