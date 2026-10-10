<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

use DateTimeImmutable;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\UnitOfWork\VerificationPipeline;
use Eleph\Runtime\Verification\EntityVerifiers;
use Eleph\Runtime\Verification\Verification;
use Eleph\Runtime\Verification\Violation;
use PHPUnit\Framework\TestCase;

final class VerificationPipelineTest extends TestCase
{
    public function testFinalStateVerifiesUnchangedDependenciesAndExplicitNulls(): void
    {
        $verifiers = new class () implements EntityVerifiers {
            public function verifiedFields(): array
            {
                return ['publishedAt', 'enquiryUrl'];
            }
            public function verify(string $field, mixed $value, MutationContext $context): Verification
            {
                if ('publishedAt' === $field && (null !== $context->original($field) && null === $value || 'published' === $context->pending('status') && null === $value)) {
                    return Verification::failed(new Violation('publication.timestamp', 'Retain the first publication timestamp.'));
                }
                return Verification::ok();
            }
        };
        $processors = $this->createMock(ProcessorRegistry::class);
        $processors->expects(self::never())->method('write');
        $pipeline = new VerificationPipeline(['Page' => $verifiers], [], $processors, nonNullable: ['Page' => ['title', 'content']]);
        $clear = new Mutation('Page', EntityId::of(1), ['publishedAt' => new DateTimeImmutable()]);
        $clear->set('publishedAt', null);
        self::assertSame('publication.timestamp', $pipeline->verify($clear)[0]->violation->code);
        $publish = new Mutation('Page', EntityId::of(1), ['publishedAt' => null]);
        $publish->set('status', 'published');
        self::assertSame('publication.timestamp', $pipeline->verify($publish)[0]->violation->code);
        $optional = new Mutation('Page', EntityId::of(1), ['enquiryUrl' => 'https://example.com']);
        $optional->set('enquiryUrl', null);
        self::assertSame([], $pipeline->verify($optional));
        $invalid = new Mutation('Page', EntityId::of(1));
        $invalid->set('title', null);
        $invalid->set('content', null);
        self::assertSame(['title', 'content'], array_map(static fn ($v): string => $v->field, $pipeline->verify($invalid)));
    }
}
