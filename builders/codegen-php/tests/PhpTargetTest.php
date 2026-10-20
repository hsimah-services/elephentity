<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Tests;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\PhpTarget;
use Eleph\Gen\Php\TargetRequest;
use Eleph\Gen\Php\Wire\IrCodec;
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
            'Author/Author.php',
            'Author/AuthorDeleter.php',
            'Author/AuthorHydrator.php',
            'Author/AuthorInput.php',
            'Author/AuthorMutationContext.php',
            'Author/AuthorMutator.php',
            'Author/AuthorPreCommitContext.php',
            'Author/AuthorReadPolicies.php',
            'Author/AuthorSideEffects.php',
            'Author/AuthorVerifiers.php',
            'Author/AuthorWriteContext.php',
            'Author/AuthorWritePolicies.php',
            'Catalogue.php',
            'Comment/Comment.php',
            'Comment/CommentDeleter.php',
            'Comment/CommentHydrator.php',
            'Comment/CommentInput.php',
            'Comment/CommentMutationContext.php',
            'Comment/CommentMutator.php',
            'Comment/CommentPreCommitContext.php',
            'Comment/CommentReadPolicies.php',
            'Comment/CommentSideEffects.php',
            'Comment/CommentVerifiers.php',
            'Comment/CommentWriteContext.php',
            'Comment/CommentWritePolicies.php',
            'Enum/PostStatus.php',
            'Enum/PostVisibility.php',
            'Pattern/Auditable/Auditable.php',
            'Pattern/Auditable/AuditableMutatorTrait.php',
            'Pattern/Auditable/Contract/AuditableOwnerReadPolicy.php',
            'Post/Contract/PostAuditSideEffect.php',
            'Post/Contract/PostNoBackwardsWritePolicy.php',
            'Post/Contract/PostPriceVerifier.php',
            'Post/Contract/PostPublishAction.php',
            'Post/Contract/PostPublishedQuery.php',
            'Post/Contract/PostReindexSideEffect.php',
            'Post/Contract/PostReporterReadPolicy.php',
            'Post/Post.php',
            'Post/PostDeleter.php',
            'Post/PostFinder.php',
            'Post/PostHydrator.php',
            'Post/PostInput.php',
            'Post/PostMutationContext.php',
            'Post/PostMutator.php',
            'Post/PostPreCommitContext.php',
            'Post/PostPublishArguments.php',
            'Post/PostPublishContext.php',
            'Post/PostReadPolicies.php',
            'Post/PostSideEffects.php',
            'Post/PostVerifiers.php',
            'Post/PostWriteContext.php',
            'Post/PostWritePolicies.php',
            'Tag/Tag.php',
            'Tag/TagDeleter.php',
            'Tag/TagHydrator.php',
            'Tag/TagInput.php',
            'Tag/TagMutationContext.php',
            'Tag/TagMutator.php',
            'Tag/TagPreCommitContext.php',
            'Tag/TagReadPolicies.php',
            'Tag/TagSideEffects.php',
            'Tag/TagVerifiers.php',
            'Tag/TagWriteContext.php',
            'Tag/TagWritePolicies.php',
            'Type/MoneyReadProcessor.php',
            'Type/MoneyWriteProcessor.php',
            'Wiring.php',
            'class-map.php',
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

    public function testAnEdgeIsWritableAndItsShapeMirrorsTheReadModel(): void
    {
        // Reading an edge worked and writing one had no generated path: an "item" key
        // handed to the gateway was silently dropped and the row landed with a null
        // foreign key.
        $mutator = $this->file('Post/PostMutator.php');

        // To-many: the same EdgeMutation an action context exposes, so add, remove and
        // replace all exist without three method names per edge.
        self::assertStringContainsString('public function comments(): EdgeMutation', $mutator);
        self::assertStringContainsString("return \$this->buffer->edge('comments');", $mutator);

        // To-one: a signature that cannot express two targets.
        $comment = $this->file('Comment/CommentMutator.php');

        self::assertStringContainsString('public function setAuthor(?Identifier $author): self', $comment);
        self::assertStringContainsString(
            "\$this->buffer->edge('author')->set(null === \$author ? [] : [\$author]);",
            $comment,
        );
    }

    public function testAnEdgeKeyInInputIsAppliedRatherThanDropped(): void
    {
        $input = $this->file('Post/PostInput.php');

        self::assertStringContainsString("if (array_key_exists('comments', \$input)) {", $input);
        self::assertStringContainsString("\$buffer->edge('comments')->set(\$this->comments(\$input['comments']))", $input);
        self::assertStringContainsString("\$this->decode->id(\$id, 'Post.comments')", $input);
    }

    public function testAnInverseGetsAnAccessorOnTheEntityItPointsAt(): void
    {
        // `inverse:` was accepted and generated nothing, which removed the query the
        // data existed to serve. Nothing is stored for it — the loader reads the
        // declaring entity's own edge backwards.
        $comment = $this->file('Comment/Comment.php');

        self::assertStringContainsString('public function getPost(): ?Post', $comment);
        self::assertStringContainsString("inverseToOne('Post', 'comments', \$this->id)", $comment);

        $author = $this->file('Author/Author.php');

        self::assertStringContainsString('public function comments(): EntityQuery', $author);
        self::assertStringContainsString("inverseToMany('Comment', 'author', \$this->id)", $author);

        // A non-unique reverse is a to-many, so it is a lazy query like any other.
        $tag = $this->file('Tag/Tag.php');

        self::assertStringContainsString('public function posts(): EntityQuery', $tag);
        self::assertStringContainsString("inverseToMany('Post', 'tags', \$this->id)", $tag);
    }

    public function testAManagedFieldIsSettableByNobody(): void
    {
        // The framework stamps it, so a setter or an input branch would be a way to
        // overwrite what it stamped — and on the GraphQL side it is what made a
        // machine-managed timestamp mandatory API input.
        self::assertStringNotContainsString('setCreatedAt', $this->file('Post/PostMutator.php'));
        self::assertStringNotContainsString('setUpdatedAt', $this->file('Post/PostMutator.php'));

        $input = $this->file('Post/PostInput.php');

        self::assertStringNotContainsString("'createdAt'", $input);
        self::assertStringNotContainsString("'updatedAt'", $input);
    }

    public function testTheCatalogueCarriesWhatTheRuntimeCannotWorkOut(): void
    {
        // The runtime has no schema, so `required`, `unique` and `managed` change
        // nothing unless the generator writes them down.
        $catalogue = $this->file('Catalogue.php');

        // required, minus the managed fields the framework fills for itself.
        self::assertStringContainsString("'Post' => ['title', 'status'],", $catalogue);
        self::assertStringContainsString("'Post' => ['postId'],", $catalogue);
        self::assertStringContainsString("'Post.createdAt' => Managed::Created,", $catalogue);
        self::assertStringContainsString("'Post.updatedAt' => Managed::Modified,", $catalogue);
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

        self::assertStringContainsString('public function context(): PostMutationContext', $context);

        // Everything else the entity has must be absent.
        self::assertStringNotContainsString('setTitle', $context);
        self::assertStringNotContainsString('setPrice', $context);
        self::assertStringNotContainsString('function tags', $context);
    }

    public function testAFieldVerifierIsTypedToBothTheEntityAndTheFieldType(): void
    {
        self::assertStringContainsString(
            'public function verify(?Money $value, PostMutationContext $context): Verification;',
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
        self::assertStringContainsString('assert(null === $value || $value instanceof Money);', $bridge);
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

    public function testSideEffectsDispatchInDeclarationOrderGuardedByPhaseAndEvent(): void
    {
        $bridge = $this->file('Post/PostSideEffects.php');

        self::assertStringContainsString(
            'if (SideEffectPhase::PostCommit === $phase && in_array($event, [SideEffectEvent::Create, SideEffectEvent::Update], true)) {',
            $bridge,
        );

        // audit is declared before reindex in the pattern and the entity respectively.
        self::assertLessThan(
            strpos($bridge, 'reindexSideEffect->handle'),
            (int) strpos($bridge, 'auditSideEffect->handle'),
        );
    }

    public function testWhatGeneratedCodeBuildsIsSealedBehindANamedConstructor(): void
    {
        foreach (['Post/Post.php', 'Post/PostMutationContext.php', 'Post/PostPreCommitContext.php', 'Post/PostPublishContext.php'] as $path) {
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
            'Post/PostSideEffects.php',
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

    public function testWiringThreadsEveryContractADeclaredTypeFieldNeedsIntoBothHydratorAndInput(): void
    {
        // Post.price is a Money field: the exact case a hand-typed Bootstrap.php got
        // wrong, because nothing about reading the code flags that this entity's
        // hydrator and input take a second constructor argument every other entity's
        // don't.
        $wiring = $this->file('Wiring.php');

        self::assertStringContainsString(
            'PostHydrator::class => static fn (ContainerInterface $c): object => '
                . 'new PostHydrator(self::resolve($c, ValueDecoder::class), self::resolve($c, MoneyReadProcessor::class)),',
            $wiring,
        );
        self::assertStringContainsString(
            'PostInput::class => static fn (ContainerInterface $c): object => '
                . 'new PostInput(self::resolve($c, ValueDecoder::class), self::resolve($c, MoneyReadProcessor::class)),',
            $wiring,
        );
    }

    public function testWiringThreadsASideEffectsVerifiersAndFinderContractsInDeclarationOrder(): void
    {
        $wiring = $this->file('Wiring.php');

        self::assertStringContainsString(
            'PostSideEffects::class => static fn (ContainerInterface $c): object => '
                . 'new PostSideEffects(self::resolve($c, PostAuditSideEffect::class), self::resolve($c, PostReindexSideEffect::class)),',
            $wiring,
        );
        self::assertStringContainsString(
            'PostVerifiers::class => static fn (ContainerInterface $c): object => '
                . 'new PostVerifiers(self::resolve($c, PostPriceVerifier::class)),',
            $wiring,
        );
        self::assertStringContainsString(
            'PostFinder::class => static fn (ContainerInterface $c): object => '
                . 'new PostFinder(self::resolve($c, PostPublishedQuery::class)),',
            $wiring,
        );
    }

    public function testWiringNarrowsWhatTheContainerHandsBackBeforeConstructing(): void
    {
        // ContainerInterface::get() returns mixed, so every arm above needs this
        // exact narrowing step before PHPStan level max accepts the constructor
        // call — the same reason Catalogue::resolve() exists.
        $wiring = $this->file('Wiring.php');

        self::assertStringContainsString('private static function resolve(ContainerInterface $c, string $class): object', $wiring);
        self::assertStringContainsString('assert($service instanceof $class);', $wiring);
    }

    public function testWiringGivesAnEntityWithNothingToInjectAnEmptyConstructorCall(): void
    {
        // Tag has no triggers, no verified fields and no queries — its bridges still
        // need registering, just with nothing to thread through.
        $wiring = $this->file('Wiring.php');

        self::assertStringContainsString(
            'TagSideEffects::class => static fn (ContainerInterface $c): object => new TagSideEffects(),',
            $wiring,
        );
        self::assertStringContainsString(
            'TagVerifiers::class => static fn (ContainerInterface $c): object => new TagVerifiers(),',
            $wiring,
        );
    }

    public function testWiringNeverListsTheHandWrittenContractBindingsThemselves(): void
    {
        // Actions are resolved at call time through the catalogue's own resolve(),
        // never registered here — an action handler is a hand-written Contract
        // binding, exactly like a trigger or verifier handler, not a generated class.
        self::assertStringNotContainsString('PostPublishAction', $this->file('Wiring.php'));
    }

    public function testWiringGetsNoEntryForAnEntityWithNoQueries(): void
    {
        self::assertStringNotContainsString('CommentFinder', $this->file('Wiring.php'));
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
        self::assertStringContainsString('PostAuditSideEffect', $catalogue);
        self::assertStringContainsString('MoneyReadProcessor', $catalogue);
        self::assertStringContainsString('MoneyWriteProcessor', $catalogue);
    }

    public function testTheCatalogueBuildsMutatorsRatherThanResolvingThem(): void
    {
        // A mutator writes into one buffer and every mutation needs its own, so it
        // cannot come from a container the way a stateless service does.
        $catalogue = $this->file('Catalogue.php');

        self::assertStringContainsString(
            "'Post' => new PostMutator(\$buffer, \$this->resolve(PostPublishAction::class))",
            $catalogue,
        );
    }

    public function testAnActionHandlerIsAssertedBeforeItReachesTheMutatorConstructor(): void
    {
        // ContainerInterface::get() returns mixed; the mutator constructor wants a
        // specific handler type, and only an assert in between satisfies PHPStan.
        $catalogue = $this->file('Catalogue.php');

        self::assertStringContainsString('$service = $this->container->get($class);', $catalogue);
        self::assertStringContainsString('assert($service instanceof $class);', $catalogue);
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

        self::assertStringContainsString('private function publishedAt(mixed $value): ?DateTimeImmutable', $input);
        self::assertStringContainsString("\$this->decode->datetime(\$value, 'Post.publishedAt')", $input);
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

    public function testTheClassMapAddressesEveryClassTheTreeContains(): void
    {
        // `eleph check` loads the tree through this map and has no other way to find a
        // class, so a map that misses a file is a gate that silently stops checking it.
        $map = $this->classMap();
        $expected = array_values(array_filter(
            array_keys($this->generated()),
            static fn (string $path): bool => 'class-map.php' !== $path,
        ));

        sort($expected);
        $actual = array_values($map['classes']);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testTheClassMapResolvesEverySpecNameToItsEntityClass(): void
    {
        $map = $this->classMap();

        self::assertSame([
            'Author' => 'App\\Elephentity\\Author\\Author',
            'Comment' => 'App\\Elephentity\\Comment\\Comment',
            'Post' => 'App\\Elephentity\\Post\\Post',
            'Tag' => 'App\\Elephentity\\Tag\\Tag',
        ], $map['entities']);

        // Every entity class it names is one the tree actually holds.
        foreach ($map['entities'] as $class) {
            self::assertArrayHasKey($class, $map['classes']);
        }
    }

    /**
     * @return array{entities: array<string, string>, classes: array<string, string>}
     */
    private function classMap(): array
    {
        $body = $this->generated()['class-map.php'] ?? null;

        self::assertIsString($body);

        /** @var mixed $map */
        $map = eval($body);

        self::assertIsArray($map);
        self::assertIsArray($map['entities'] ?? null);
        self::assertIsArray($map['classes'] ?? null);

        /** @var array{entities: array<string, string>, classes: array<string, string>} $map */
        return $map;
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
        // The IR as it arrives on the wire, not as a compiler produced it. This builder
        // has no compiler and never will, so the input is the same committed request a
        // rewrite in another language gets pointed at.
        $request = file_get_contents(__DIR__ . '/fixtures/golden/valid/request.json');

        self::assertIsString($request);

        /** @var array{schema: array<string, mixed>} $wire */
        $wire = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
        $decoded = $wire['schema'];

        $response = (new PhpTarget())->generate(
            TargetRequest::of('/tmp/eleph-not-written', [
                'namespace' => 'App\\Elephentity',
                'typeNamespace' => 'App\\Type',
            ]),
            IrCodec::decode($decoded),
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
