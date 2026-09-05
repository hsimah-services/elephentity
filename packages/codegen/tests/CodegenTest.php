<?php

declare(strict_types=1);

namespace PheFr\Codegen\Tests;

use PheFr\Codegen\Codegen;
use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\GeneratorConfig;
use PheFr\Schema\SchemaCompiler;
use PheFr\Schema\SpecSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CodegenTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $generated = null;

    public function testEveryDeclaredConstructProducesAFile(): void
    {
        $paths = array_keys($this->generated());

        sort($paths);

        self::assertSame([
            'Bridge/CommentHydrator.php',
            'Bridge/CommentTriggers.php',
            'Bridge/CommentVerifiers.php',
            'Bridge/PostHydrator.php',
            'Bridge/PostTriggers.php',
            'Bridge/PostVerifiers.php',
            'Bridge/TagHydrator.php',
            'Bridge/TagTriggers.php',
            'Bridge/TagVerifiers.php',
            'Comment.php',
            'CommentMutationContext.php',
            'CommentMutator.php',
            'Context/PostPublishContext.php',
            'Contract/Action/PostPublishAction.php',
            'Contract/Query/PostPublishedQuery.php',
            'Contract/Trigger/PostAuditTrigger.php',
            'Contract/Trigger/PostReindexTrigger.php',
            'Contract/Type/MoneyReadProcessor.php',
            'Contract/Type/MoneyWriteProcessor.php',
            'Contract/Verifier/PostPriceVerifier.php',
            'Enum/PostStatus.php',
            'Enum/PostVisibility.php',
            'Post.php',
            'PostFinder.php',
            'PostMutationContext.php',
            'PostMutator.php',
            'Tag.php',
            'TagMutationContext.php',
            'TagMutator.php',
        ], $paths);
    }

    public function testAnEntityWithNoQueriesGetsNoFinder(): void
    {
        self::assertArrayNotHasKey('CommentFinder.php', $this->generated());
    }

    public function testAnImmutableFieldGetsNoSetter(): void
    {
        // Write-once is enforced by the absence of a method, which nothing can forget.
        $mutator = $this->file('PostMutator.php');

        self::assertStringContainsString('public function setTitle(', $mutator);
        self::assertStringNotContainsString('setCreatedAt', $mutator);
    }

    public function testFieldsFromPatternsAreGeneratedLikeAnyOther(): void
    {
        $post = $this->file('Post.php');

        // createdAt and updatedAt arrive via Auditable → Timestamps; postId via
        // WordPressPost. Nothing in the generated code distinguishes them.
        self::assertStringContainsString('public function getCreatedAt(): DateTimeImmutable', $post);
        self::assertStringContainsString('public function getPostId(): int', $post);
    }

    public function testToManyEdgesReturnALazyQueryAndToOneReturnsTheEntity(): void
    {
        $post = $this->file('Post.php');

        self::assertStringContainsString('public function comments(): EntityQuery', $post);
        self::assertStringContainsString('@return EntityQuery<Comment>', $post);
        self::assertStringNotContainsString('public function getComments(): array', $post);
    }

    public function testAnActionContextExposesOnlyItsDeclaredWrites(): void
    {
        // Post.publish declares writes: fields [status], edges [comments].
        $context = $this->file('Context/PostPublishContext.php');

        self::assertStringContainsString('public function setStatus(PostStatus $status)', $context);
        self::assertStringContainsString('public function comments(): EdgeMutation', $context);

        // Everything else the entity has must be absent.
        self::assertStringNotContainsString('setTitle', $context);
        self::assertStringNotContainsString('setPrice', $context);
        self::assertStringNotContainsString('function tags', $context);
    }

    public function testAFieldVerifierIsTypedToBothTheEntityAndTheFieldType(): void
    {
        self::assertStringContainsString(
            'public function verify(Money $value, PostMutationContext $context): Verification;',
            $this->file('Contract/Verifier/PostPriceVerifier.php'),
        );
    }

    public function testInlineAndDeclaredEnumsGenerateIdenticallyShapedClasses(): void
    {
        $declared = $this->file('Enum/PostStatus.php');
        $inline = $this->file('Enum/PostVisibility.php');

        self::assertStringContainsString('enum PostStatus: string', $declared);
        self::assertStringContainsString("case Draft = 'draft';", $declared);

        self::assertStringContainsString('enum PostVisibility: string', $inline);
        self::assertStringContainsString("case Public = 'public';", $inline);
    }

    public function testProcessorInterfacesNarrowTheirReturnTypes(): void
    {
        self::assertStringContainsString(
            'public function read(mixed $value): Money;',
            $this->file('Contract/Type/MoneyReadProcessor.php'),
        );
        self::assertStringContainsString(
            'public function write(mixed $value): int;',
            $this->file('Contract/Type/MoneyWriteProcessor.php'),
        );
    }

    public function testTypedContextAccessorsNarrowWhatTheGenericContextReturns(): void
    {
        $context = $this->file('PostMutationContext.php');

        self::assertStringContainsString('public function originalPrice(): ?Money', $context);
        self::assertStringContainsString('assert(null === $value || $value instanceof Money);', $context);
        self::assertStringContainsString('public function pendingTitle(): ?string', $context);
        self::assertStringContainsString('assert(null === $value || is_string($value));', $context);
    }

    public function testTheVerifierBridgeNarrowsBeforeCallingTheTypedInterface(): void
    {
        // PHP forbids narrowing a parameter type in an implementation, so the runtime
        // cannot call PostPriceVerifier::verify(Money, PostMutationContext) through any
        // shared interface. Generated code is allowed to know both sides.
        $bridge = $this->file('Bridge/PostVerifiers.php');

        self::assertStringContainsString('implements EntityVerifiers', $bridge);
        self::assertStringContainsString("return ['price'];", $bridge);
        self::assertStringContainsString('assert($value instanceof Money);', $bridge);
        self::assertStringContainsString(
            'return $this->priceVerifier->verify($value, new PostMutationContext($context));',
            $bridge,
        );
    }

    public function testAnEntityWithNoVerifiedFieldsStillGetsABridge(): void
    {
        // The runtime should not have to check whether a bridge exists.
        $bridge = $this->file('Bridge/TagVerifiers.php');

        self::assertStringContainsString('return [];', $bridge);
        self::assertStringContainsString('return Verification::ok();', $bridge);
    }

    public function testTriggersDispatchInDeclarationOrderGuardedByPhaseAndEvent(): void
    {
        $bridge = $this->file('Bridge/PostTriggers.php');

        self::assertStringContainsString(
            'if (TriggerPhase::PostCommit === $phase && in_array($event, [TriggerEvent::Create, TriggerEvent::Update], true)) {',
            $bridge,
        );

        // audit is declared before reindex in the pattern and the entity respectively.
        self::assertLessThan(
            strpos($bridge, 'reindexTrigger->handle'),
            (int) strpos($bridge, 'auditTrigger->handle'),
        );
    }

    public function testTheHydratorCallsTheGeneratedConstructorWithExactTypes(): void
    {
        $hydrator = $this->file('Bridge/PostHydrator.php');

        self::assertStringContainsString('public function hydrate(Record $record, EdgeLoader $edges): Post', $hydrator);
        self::assertStringContainsString('private function title(Record $record): string', $hydrator);
        self::assertStringContainsString('private function price(Record $record): ?Money', $hydrator);
        self::assertStringContainsString('private function status(Record $record): PostStatus', $hydrator);
    }

    public function testTheHydratorShortCircuitsNullAndRunsProcessorsOnDeclaredTypes(): void
    {
        $hydrator = $this->file('Bridge/PostHydrator.php');

        // Null never reaches a processor, on the way up as on the way down.
        self::assertStringContainsString(
            "return null === \$value ? null : \$this->moneyReader->read(\$this->decode->int(\$value, 'Post.price'));",
            $hydrator,
        );

        // A required field has no null branch at all.
        self::assertStringContainsString(
            "return \$this->decode->string(\$value, 'Post.title');",
            $hydrator,
        );
    }

    public function testTheHydratorTakesOnlyTheProcessorsItsFieldsNeed(): void
    {
        // Tag has no declared types, so its hydrator takes the decoder and nothing else.
        self::assertStringNotContainsString('Reader', $this->file('Bridge/TagHydrator.php'));
    }

    public function testGenerationIsDeterministic(): void
    {
        self::assertSame($this->compileAndGenerate(), $this->compileAndGenerate());
    }

    public function testGeneratedCodeParses(): void
    {
        foreach ($this->generated() as $path => $body) {
            $file = tempnam(sys_get_temp_dir(), 'phefr') . '.php';
            file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body);

            $output = [];
            $status = 0;
            exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $status);
            unlink($file);

            self::assertSame(0, $status, sprintf("%s does not parse:\n%s", $path, implode("\n", $output)));
        }
    }

    private function file(string $path): string
    {
        $generated = $this->generated();

        self::assertArrayHasKey($path, $generated);

        return $generated[$path];
    }

    /**
     * @return array<string, string>
     */
    private function generated(): array
    {
        return self::$generated ??= $this->compileAndGenerate();
    }

    /**
     * @return array<string, string>
     */
    private function compileAndGenerate(): array
    {
        $compiled = (new SchemaCompiler())->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        $files = (new Codegen(new GeneratorConfig(
            'App\\PheFr',
            '/tmp/phefr-not-written',
            'App\\Type',
        )))->generate($compiled->schema());

        $byPath = [];

        foreach ($files as $file) {
            self::assertInstanceOf(GeneratedFile::class, $file);
            $byPath[$file->relativePath] = $file->body;
        }

        return $byPath;
    }
}
