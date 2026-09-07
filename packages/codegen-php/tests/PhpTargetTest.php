<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Tests;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\PhpTarget;
use Eleph\Codegen\TargetRequest;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpTargetTest extends TestCase
{
    /** @var array<string, string>|null */
    private static ?array $generated = null;

    public function testEveryDeclaredConstructProducesAFile(): void
    {
        $paths = array_keys($this->generated());

        sort($paths);

        self::assertSame([
            'Catalogue.php',
            'Comment/Comment.php',
            'Comment/CommentDeleter.php',
            'Comment/CommentHydrator.php',
            'Comment/CommentInput.php',
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
            'Post/PostDeleter.php',
            'Post/PostFinder.php',
            'Post/PostHydrator.php',
            'Post/PostInput.php',
            'Post/PostMutationContext.php',
            'Post/PostMutator.php',
            'Post/PostPublishContext.php',
            'Post/PostTriggers.php',
            'Post/PostVerifiers.php',
            'Tag/Tag.php',
            'Tag/TagDeleter.php',
            'Tag/TagHydrator.php',
            'Tag/TagInput.php',
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
        self::assertStringContainsString('public function getPostId(): ?int', $post);
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

    public function testWhatGeneratedCodeBuildsIsSealedBehindANamedConstructor(): void
    {
        // One entry point rather than two: `new Post(...)` beside `Post::of(...)` says
        // nothing about which is intended, and it matches the runtime's own style.
        foreach (['Post/Post.php', 'Post/PostMutationContext.php', 'Post/PostPublishContext.php'] as $path) {
            $source = $this->file($path);

            self::assertStringContainsString('private function __construct(', $source, $path);
            self::assertStringContainsString('public static function of(', $source, $path);
        }
    }

    public function testWhatAContainerBuildsKeepsAPublicConstructor(): void
    {
        // Every mainstream container autowires through a public constructor. Sealing
        // services would buy uniformity at the price of an explicit service definition
        // per entity, forever.
        foreach ([
            'Post/PostMutator.php',
            'Post/PostFinder.php',
            'Post/PostHydrator.php',
            'Post/PostVerifiers.php',
            'Post/PostTriggers.php',
        ] as $path) {
            $source = $this->file($path);

            self::assertStringContainsString('public function __construct(', $source, $path);
            self::assertStringNotContainsString('public static function of(', $source, $path);
        }
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

    public function testADeleterKnowsWhatDependsOnItsEntity(): void
    {
        // Found by reading every *other* entity's edges: a Post learns about Comments
        // from Comment, not from itself.
        $deleter = $this->file('Post/PostDeleter.php');

        self::assertStringContainsString('public function delete(EntityId $id): void', $deleter);
        self::assertStringContainsString("new Deletion('Post', \$id)", $deleter);
        self::assertStringContainsString(
            "new DeletionRule('Comment', 'comments', 'Post', DeletionPolicy::Restrict, false)",
            $deleter,
        );
    }

    public function testAManyToManyEdgeGivesBothSidesARule(): void
    {
        // Join rows dangle whichever end goes first, so both ends must know.
        self::assertStringContainsString(
            "new DeletionRule('Tag', 'tags', 'Post', DeletionPolicy::Restrict, true)",
            $this->file('Post/PostDeleter.php'),
        );
        self::assertStringContainsString(
            "new DeletionRule('Post', 'tags', 'Post', DeletionPolicy::Restrict, true)",
            $this->file('Tag/TagDeleter.php'),
        );
    }

    public function testAnEntityNothingDependsOnHasNoRules(): void
    {
        self::assertStringContainsString('return [];', $this->file('Comment/CommentDeleter.php'));
    }

    public function testTheCatalogueListsEveryContractTheProjectOwes(): void
    {
        // What the boot check reads. Generated from the spec, so it cannot drift from
        // what was actually emitted.
        $catalogue = $this->file('Catalogue.php');

        self::assertStringContainsString('PostPriceVerifier', $catalogue);
        self::assertStringContainsString('PostPublishAction', $catalogue);
        self::assertStringContainsString('PostAuditTrigger', $catalogue);
        self::assertStringContainsString('MoneyReadProcessor', $catalogue);
        self::assertStringContainsString('MoneyWriteProcessor', $catalogue);
    }

    public function testTheCatalogueBuildsMutatorsRatherThanResolvingThem(): void
    {
        // A mutator writes into one buffer and every mutation needs its own, so it
        // cannot come from a container the way a stateless service does.
        $catalogue = $this->file('Catalogue.php');

        self::assertStringContainsString(
            "'Post' => new PostMutator(\$buffer, \$this->container->get(PostPublishAction::class))",
            $catalogue,
        );
    }

    public function testAnInputApplierOnlyTouchesKeysThatWereSupplied(): void
    {
        // What makes a partial update partial.
        $input = $this->file('Post/PostInput.php');

        self::assertStringContainsString("if (array_key_exists('title', \$input)) {", $input);
        self::assertStringContainsString("\$buffer->set('title', \$this->title(\$input['title']))", $input);
    }

    public function testAnInputApplierConvertsToTheTypeTheSetterWants(): void
    {
        // A protocol layer hands over '2026-09-06'; the setter wants a
        // DateTimeImmutable, and only generated code knows both ends.
        $input = $this->file('Post/PostInput.php');

        self::assertStringContainsString('private function createdAt(mixed $value): ?DateTimeImmutable', $input);
        self::assertStringContainsString("\$this->decode->datetime(\$value, 'Post.createdAt')", $input);
        self::assertStringContainsString('$this->moneyReader->read(', $input);
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
        $compiled = (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/../../schema/tests/fixtures/valid'),
        );

        self::assertTrue($compiled->isSuccess());

        $response = (new PhpTarget())->generate(
            TargetRequest::of('/tmp/eleph-not-written', [
                'namespace' => 'App\\Elephentity',
                'typeNamespace' => 'App\\Type',
            ]),
            $compiled->schema(),
        );

        self::assertSame([], $response->errors);

        $files = $response->files;

        $byPath = [];

        foreach ($files as $file) {
            self::assertInstanceOf(GeneratedFile::class, $file);
            $byPath[$file->relativePath] = $file->body;
        }

        return $byPath;
    }
}
