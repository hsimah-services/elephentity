<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\EntitySideEffects;
use Eleph\Runtime\Mutation\MutableMutationContext;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\SideEffect\SideEffectEvent;
use Eleph\Runtime\SideEffect\SideEffectPhase;
use Eleph\Runtime\UnitOfWork\SideEffectDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SideEffectDispatcherTest extends TestCase
{
    public function testPostCommitFailureIsLoggedAndLaterHandlersStillRun(): void
    {
        $effects = new class () implements EntitySideEffects {
            public bool $finished = false;
            public function handlers(SideEffectPhase $phase, SideEffectEvent $event, MutableMutationContext $context): iterable
            {
                yield static function (): void {
                    throw new RuntimeException('first failed');
                };
                yield function (): void {
                    $this->finished = true;
                };
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('post-commit'),
            self::callback(static fn (array $context): bool => ($context['exception'] ?? null) instanceof RuntimeException),
        );
        (new SideEffectDispatcher(['Post' => $effects], $logger))->dispatch(SideEffectPhase::PostCommit, [new Mutation('Post', EntityId::of(1))]);
        self::assertTrue($effects->finished);
    }

    public function testPreCommitHandlersObserveEarlierChangesAndFailureStopsTheSequence(): void
    {
        $effects = new class () implements EntitySideEffects {
            public function handlers(SideEffectPhase $phase, SideEffectEvent $event, MutableMutationContext $context): iterable
            {
                yield static function () use ($context): void {
                    $context->set('title', 'first');
                };
                yield static function () use ($context): void {
                    TestCase::assertSame('first', $context->pending('title'));
                    throw new RuntimeException('second failed');
                };
                yield static function (): void {
                    TestCase::fail('Third handler must not run.');
                };
            }
        };
        $this->expectExceptionMessage('second failed');
        (new SideEffectDispatcher(['Post' => $effects]))->dispatch(SideEffectPhase::PreCommit, [new Mutation('Post', EntityId::of(1))]);
    }
}
