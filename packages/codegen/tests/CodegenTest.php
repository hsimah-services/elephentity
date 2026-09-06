<?php

declare(strict_types=1);

namespace Eleph\Codegen\Tests;

use Eleph\Codegen\Codegen;
use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\GeneratorConfig;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
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
            'Comment/Comment.php',
            'Comment/CommentHydrator.php',
            'Comment/CommentMutationContext.php',
            'Comment/CommentMutator.php',
            'Comment/CommentTriggers.php',
            'Comment/CommentVerifiers.php',
            'Enum/PostStatus.php',
            'Enum/PostVisibility.php',
            'Post/Contract/PostAuditTrigger.php',
            'Post/Contract/PostPriceVerifier.php',
            'Post/Contract/PostPublishAction.php',
            'Post/Contract/PostPublishedQuery.php',
            'Post/Contract/PostReindexTrigger.php',
            'Post/Post.php',
            'Post/PostFinder.php',
            'Post/PostHydrator.php',
            'Post/PostMutationContext.php',
            'Post/PostMutator.php',
            'Post/PostPublishContext.php',
            'Post/PostTriggers.php',
            'Post/PostVerifiers.php',
            'Tag/Tag.php',
            'Tag/TagHydrator.php',
            'Tag/TagMutationContext.php',
            'Tag/TagMutator.php',
            'Tag/TagTriggers.php',
            'Tag/TagVerifiers.php',
            'Type/MoneyReadProcessor.php',
            'Type/MoneyWriteProcessor.php',
        ], $paths);
    }

    public function testAnEntityWithNoQueriesGetsNoFinder(): void
    {
        self::assertArrayNotHasKey('Comment/CommentFinder.php', $this->generated());
    }

    public function testAnImmutableFieldGetsNoSetter(): void
    {
        // Write-once is enforced by the absence of a method, which nothing can forget.
        $mutator = $this->file('Post/PostMutator.php');

        self::assertStringContainsString('public function setTitle(', $mutator);
        self::assertStringNotContainsString('setCreatedAt', $mutator);
    }

    public function testFieldsFromPatternsAreGeneratedLikeAnyOther(): void
    {
        $post = $this->file('Post/Post.php');

        // createdAt and updatedAt arrive via Auditable → Timestamps; postId via
        // WordPressPost. Nothing in the generated code distinguishes them.
        self::assertStringContainsString('public function getCreatedAt(): DateTimeImmutable', $post);
        self::assertStringContainsString('public function getPostId(): int', $post);
    }

    public function testToManyEdgesReturnALazyQueryAndToOneReturnsTheEntity(): void
    {
        $post = $this->file('Post/Post.php');

        self::assertStringContainsString('public function comments(): EntityQuery', $post);
        self::assertStringContainsString('@return EntityQuery<Comment>', $post);
        self::assertStringNotContainsString('public function getComments(): array', $post);
    }

    public function testAnActionContextExposesOnlyItsDeclaredWrites(): void
    {
        // Post.publish declares writes: fields [status], edges [comments].
        $context = $this->file('Post/PostPublishContext.php');

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
            $this->file('Post/Contract/PostPriceVerifier.php'),
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
            $this->file('Type/MoneyReadProcessor.php'),
        );
        self::assertStringContainsString(
            'public function write(mixed $value): int;',
            $this->file('Type/MoneyWriteProcessor.php'),
        );
    }

    public function testTypedContextAccessorsNarrowWhatTheGenericContextReturns(): void
    {
        $context = $this->file('Post/PostMutationContext.php');

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
        $bridge = $this->file('Post/PostVerifiers.php');

        self::assertStringContainsString('implements EntityVerifiers', $bridge);
        self::assertStringContainsString("return ['price'];", $bridge);
        self::assertStringContainsString('assert($value instanceof Money);', $bridge);
        self::assertStringContainsString(
            'return $this->priceVerifier->verify($value, PostMutationContext::of($context));',
            $bridge,
        );
    }

    public function testAnEntityWithNoVerifiedFieldsStillGetsABridge(): void
    {
        // The runtime should not have to check whether a bridge exists.
        $bridge = $this->file('Tag/TagVerifiers.php');

        self::assertStringContainsString('return [];', $bridge);
        self::assertStringContainsString('return Verification::ok();', $bridge);
    }

    public function testTriggersDispatchInDeclarationOrderGuardedByPhaseAndEvent(): void
    {
        $bridge = $this->file('Post/PostTriggers.php');

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

    public function testGeneratedClassesAreSealedBehindANamedConstructor(): void
    {
        // One entry point rather than two: `new Post(...)` beside `Post::of(...)` says
        // nothing about which is intended, and it matches the runtime's own style.
        $post = $this->file('Post/Post.php');

        self::assertStringContainsString('private function __construct(', $post);
        self::assertStringContainsString('public static function of(', $post);
        self::assertStringContainsString('return new self($id, $edges,', $post);
    }

    public function testTheHydratorCallsTheGeneratedConstructorWithExactTypes(): void
    {
        $hydrator = $this->file('Post/PostHydrator.php');

        self::assertStringContainsString('public function hydrate(Record $record, EdgeLoader $edges): Post', $hydrator);
        self::assertStringContainsString('private function title(Record $record): string', $hydrator);
        self::assertStringContainsString('private function price(Record $record): ?Money', $hydrator);
        self::assertStringContainsString('private function status(Record $record): PostStatus', $hydrator);
    }

    public function testTheHydratorShortCircuitsNullAndRunsProcessorsOnDeclaredTypes(): void
    {
        $hydrator = $this->file('Post/PostHydrator.php');

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
        self::assertStringNotContainsString('Reader', $this->file('Tag/TagHydrator.php'));
    }

    public function testGenerationIsDeterministic(): void
    {
        self::assertSame($this->compileAndGenerate(), $this->compileAndGenerate());
    }

    public function testGeneratedCodeParses(): void
    {
        foreach ($this->generated() as $path => $body) {
            $file = tempnam(sys_get_temp_dir(), 'eleph') . '.php';
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
            'App\\Elephentity',
            '/tmp/eleph-not-written',
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
