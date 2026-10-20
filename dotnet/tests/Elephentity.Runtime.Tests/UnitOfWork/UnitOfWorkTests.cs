using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.SideEffect;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Type;
using Elephentity.Runtime.UnitOfWork;
using Elephentity.Runtime.Verification;
using Mut = Elephentity.Runtime.Mutation.Mutation;
using Record = Elephentity.Runtime.Storage.Record;
using Ver = Elephentity.Runtime.Verification.Verification;
using Work = Elephentity.Runtime.UnitOfWork.UnitOfWork;

namespace Elephentity.Runtime.Tests.UnitOfWork;

public sealed class UnitOfWorkTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task AnEmptyCommitTouchesNothing()
    {
        var storage = new FakeStorage();

        await Build(storage).CommitAsync(Token);

        Assert.Empty(storage.Log);
    }

    [Fact]
    public async Task AMutationThatSetsNothingIsNotACommit()
    {
        var storage = new FakeStorage();
        var work = Build(storage);

        work.Register(new Mut("Post", EntityId.Of(1)));

        Assert.True(work.IsEmpty());
        await work.CommitAsync(Token);
        Assert.Empty(storage.Log);
    }

    [Fact]
    public async Task ARejectedCommitWritesNothingAndNamesEveryProblem()
    {
        var storage = new FakeStorage();
        var work = Build(
            storage,
            verifiers: new()
            {
                ["Post"] = new StubVerifiers(new()
                {
                    ["title"] = Ver.Failed(new Violation("post.title.empty", "Must not be empty.")),
                    ["slug"] = Ver.Failed(new Violation("post.slug.taken", "Already taken.")),
                }),
            });

        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", string.Empty);
        mutation.Assign("slug", "hello");
        work.Register(mutation);

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() => work.CommitAsync(Token));

        Assert.Equal(["Post.title", "Post.slug"], rejected.Paths());
        Assert.Empty(storage.Log);
    }

    [Fact]
    public async Task ParentsAreWrittenBeforeTheChildrenThatReferenceThem()
    {
        var storage = new FakeStorage();
        var work = Build(storage);
        var (post, comment) = PostWithComment();

        work.Register(comment);
        work.Register(post);
        await work.CommitAsync(Token);

        var rows = storage.Batches[0].Operations;

        Assert.IsType<Insert>(rows[0]);
        Assert.Equal("Comment", rows[0].Entity);
        Assert.Equal("Post", rows[1].Entity);
    }

    [Fact]
    public async Task LinksAreWrittenAfterRowsWithRealIds()
    {
        var storage = new FakeStorage();
        var work = Build(storage);
        var (post, comment) = PostWithComment();

        work.Register(comment);
        work.Register(post);
        await work.CommitAsync(Token);

        Assert.Equal(2, storage.Batches.Count);
        var link = Assert.IsType<Link>(storage.Batches[1].Operations[0]);
        Assert.True(link.From.IsPersisted);
        Assert.True(link.To.IsPersisted);
    }

    [Fact]
    public async Task LinkingToSomethingNoOneCreatesIsRefused()
    {
        var work = Build(new FakeStorage());
        var post = new Mut("Post", new PendingId("Post"));
        post.Assign("title", "Hello");
        post.Edge("comments").Add(new PendingId("Comment"));
        work.Register(post);

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => work.CommitAsync(Token));

        Assert.Contains("which no mutation in this commit creates", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task MutuallyDependentWritesAreRefusedRatherThanLooping()
    {
        var work = Build(new FakeStorage());
        var left = new PendingId("Post");
        var right = new PendingId("Post");
        var first = new Mut("Post", left);
        first.Assign("title", "a");
        first.Edge("related").Add(right);
        var second = new Mut("Post", right);
        second.Assign("title", "b");
        second.Edge("related").Add(left);
        work.Register(first);
        work.Register(second);

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => work.CommitAsync(Token));

        Assert.Contains("depend on each other", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ACreateMissingARequiredFieldIsRejectedBeforeAnyWrite()
    {
        var storage = new FakeStorage();
        var work = Build(storage, required: new() { ["Post"] = ["title", "slug"] });
        var mutation = new Mut("Post", new PendingId("Post"));
        mutation.Assign("title", "Hello");
        work.Register(mutation);

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() => work.CommitAsync(Token));

        Assert.Equal(["Post.slug"], rejected.Paths());
        Assert.Empty(storage.Log);
    }

    [Fact]
    public async Task AnUpdateStaysPartialWhateverIsRequired()
    {
        var storage = new FakeStorage();
        var work = Build(storage, required: new() { ["Post"] = ["title", "slug"] });
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "Renamed");
        work.Register(mutation);

        await work.CommitAsync(Token);

        Assert.Equal(["begin", "update Post", "commit"], storage.Log);
    }

    [Fact]
    public async Task ACreateMissingARequiredEdgeIsRejectedBeforeAnyWrite()
    {
        var storage = new FakeStorage();
        var work = Build(storage, requiredEdges: new() { ["Comment"] = ["post"] });
        var mutation = new Mut("Comment", new PendingId("Comment"));
        mutation.Assign("body", "Hi");
        work.Register(mutation);

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() => work.CommitAsync(Token));

        Assert.Equal("edge.required", rejected.Violations[0].Violation.Code);
        Assert.Empty(storage.Log);
    }

    [Fact]
    public async Task AManagedFieldIsStampedBeforeAnythingLooksAtIt()
    {
        var storage = new FakeStorage();
        var work = Build(
            storage,
            required: new() { ["Post"] = ["createdAt"] },
            managed: new ManagedFields(new Dictionary<string, Managed>
            {
                ["Post.createdAt"] = Managed.Created,
                ["Post.updatedAt"] = Managed.Modified,
            }));
        var mutation = new Mut("Post", new PendingId("Post"));
        mutation.Assign("title", "Hello");
        work.Register(mutation);

        await work.CommitAsync(Token);

        var changes = mutation.Changes();
        Assert.IsType<DateTimeOffset>(changes["createdAt"]);
        Assert.Equal(changes["createdAt"], changes["updatedAt"]);
    }

    [Fact]
    public async Task AnUpdateStampsWhatChangedAndNotWhenItWasCreated()
    {
        var work = Build(
            new FakeStorage(),
            managed: new ManagedFields(new Dictionary<string, Managed>
            {
                ["Post.createdAt"] = Managed.Created,
                ["Post.updatedAt"] = Managed.Modified,
            }));
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "Renamed");
        work.Register(mutation);

        await work.CommitAsync(Token);

        Assert.False(mutation.IsChanged("createdAt"));
        Assert.True(mutation.IsChanged("updatedAt"));
    }

    [Fact]
    public async Task AnUntouchedEntityIsNotStamped()
    {
        var work = Build(
            new FakeStorage(),
            managed: new ManagedFields(new Dictionary<string, Managed> { ["Post.updatedAt"] = Managed.Modified }));
        var mutation = new Mut("Post", EntityId.Of(1));
        work.Register(mutation);

        await work.CommitAsync(Token);

        Assert.False(mutation.IsChanged("updatedAt"));
    }

    [Fact]
    public async Task PreCommitSideEffectsRunBeforeTheTransaction()
    {
        var storage = new FakeStorage();
        var effects = new RecordingSideEffects();
        var work = Build(storage, sideEffects: new() { ["Post"] = effects });
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "x");
        work.Register(mutation);

        await work.CommitAsync(Token);

        Assert.Equal(["preCommit:update:Post:1", "postCommit:update:Post:1"], effects.Calls);
    }

    [Fact]
    public async Task APreCommitSideEffectThrowingPreventsAllWrites()
    {
        var storage = new FakeStorage();
        var effects = new RecordingSideEffects();
        effects.FailOn(SideEffectPhase.PreCommit, new InvalidOperationException("no"));
        var work = Build(storage, sideEffects: new() { ["Post"] = effects });
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "x");
        work.Register(mutation);

        await Assert.ThrowsAsync<InvalidOperationException>(() => work.CommitAsync(Token));

        Assert.Empty(storage.Log);
    }

    [Fact]
    public async Task APostCommitSideEffectThrowingDoesNotUndoTheCommit()
    {
        var storage = new FakeStorage();
        var effects = new RecordingSideEffects();
        effects.FailOn(SideEffectPhase.PostCommit, new InvalidOperationException("mail is down"));
        var work = Build(storage, sideEffects: new() { ["Post"] = effects });
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "x");
        work.Register(mutation);

        await work.CommitAsync(Token);

        Assert.Equal(["begin", "update Post", "commit"], storage.Log);
    }

    [Fact]
    public async Task ARolledBackCreateKeepsItsPendingId()
    {
        var storage = new FakeStorage { FailCommit = new InvalidOperationException("deadlock") };
        var work = Build(storage);
        var mutation = new Mut("Post", new PendingId("Post"));
        mutation.Assign("title", "x");
        work.Register(mutation);

        await Assert.ThrowsAsync<InvalidOperationException>(() => work.CommitAsync(Token));

        Assert.False(mutation.Id.IsPersisted);
        Assert.Contains("rollback", storage.Log);
    }

    [Fact]
    public async Task EveryPlannedRemovalIsAnnouncedBeforeItHappens()
    {
        var storage = new FakeStorage();
        var effects = new RecordingSideEffects();
        var work = Build(storage, sideEffects: new() { ["Tag"] = effects }, planner: Planner(storage, []));

        work.Delete(new Deletion("Tag", EntityId.Of(7)));
        await work.CommitAsync(Token);

        Assert.Equal(["preCommit:delete:Tag:7", "postCommit:delete:Tag:7"], effects.Calls);
        Assert.Equal(["begin", "delete Tag", "commit"], storage.Log);
    }

    [Fact]
    public async Task ACascadedRowIsAnnouncedToo()
    {
        var storage = new FakeStorage();
        storage.Records["Comment"] = [new Record("Comment", EntityId.Of(10), new Dictionary<string, object?>())];
        var posts = new RecordingSideEffects();
        var comments = new RecordingSideEffects();
        var work = Build(
            storage,
            sideEffects: new() { ["Post"] = posts, ["Comment"] = comments },
            planner: Planner(storage, new() { ["Post"] = [new DeletionRule("Comment", "comments", "Post", DeletionPolicy.Cascade)] }));

        work.Delete(new Deletion("Post", EntityId.Of(1)));
        await work.CommitAsync(Token);

        Assert.Equal(["preCommit:delete:Comment:10", "postCommit:delete:Comment:10"], comments.Calls);
        Assert.Equal(["preCommit:delete:Post:1", "postCommit:delete:Post:1"], posts.Calls);
    }

    [Fact]
    public async Task ADeletionWithoutAPlannerSaysSo()
    {
        var work = Build(new FakeStorage());
        work.Delete(new Deletion("Post", EntityId.Of(1)));

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => work.CommitAsync(Token));

        Assert.Contains("DeletionPlanner", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task CommittingClearsTheUnitOfWork()
    {
        var storage = new FakeStorage();
        var work = Build(storage);
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "x");
        work.Register(mutation);

        await work.CommitAsync(Token);
        await work.CommitAsync(Token);

        Assert.Equal(1, storage.Log.Count(entry => entry == "commit"));
    }

    [Fact]
    public async Task BothVerificationTiersRunEvenWhenTheFirstFails()
    {
        var storage = new FakeStorage();
        var work = Build(
            storage,
            verifiers: new() { ["Post"] = new StubVerifiers(new() { ["title"] = Ver.Failed(new Violation("field.bad", "Bad.")) }) },
            fieldTypes: new() { ["Post.title"] = "Title" },
            processors: new StubProcessors(Ver.Failed(new Violation("type.bad", "Also bad."))));
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Assign("title", "x");
        work.Register(mutation);

        var rejected = await Assert.ThrowsAsync<CommitRejectedException>(() => work.CommitAsync(Token));

        Assert.Equal(["field.bad", "type.bad"], rejected.Violations.Select(violation => violation.Violation.Code));
    }

    private static (Mut Post, Mut Comment) PostWithComment()
    {
        var postId = new PendingId("Post");
        var commentId = new PendingId("Comment");
        var comment = new Mut("Comment", commentId);
        comment.Assign("body", "Hi");
        var post = new Mut("Post", postId);
        post.Assign("title", "Hello");
        post.Edge("comments").Add(commentId);

        return (post, comment);
    }

    internal static DeletionPlanner Planner(FakeStorage storage, Dictionary<string, IReadOnlyList<DeletionRule>> rules) =>
        new(storage, new Rules(rules));

    private static Work Build(
        FakeStorage storage,
        Dictionary<string, IEntityVerifiers>? verifiers = null,
        Dictionary<string, string>? fieldTypes = null,
        IProcessorRegistry? processors = null,
        Dictionary<string, IEntitySideEffects>? sideEffects = null,
        DeletionPlanner? planner = null,
        ManagedFields? managed = null,
        Dictionary<string, IReadOnlyList<string>>? required = null,
        Dictionary<string, IReadOnlyList<string>>? requiredEdges = null)
    {
        processors ??= new NullProcessorRegistry();
        fieldTypes ??= [];

        return new Work(
            storage,
            new VerificationPipeline(verifiers ?? [], fieldTypes, processors, required, requiredEdges),
            new ValueEncoder(fieldTypes, processors),
            new SideEffectDispatcher(sideEffects ?? []),
            managed: managed,
            planner: planner);
    }

    private sealed class Rules(Dictionary<string, IReadOnlyList<DeletionRule>> rules) : IDeletionRules
    {
        public IReadOnlyList<DeletionRule> RulesFor(string entity) => rules.GetValueOrDefault(entity) ?? [];
    }
}

/// <summary>A registry whose every type has a processor answering with one fixed verification.</summary>
internal sealed class StubProcessors(Ver verification) : IProcessorRegistry
{
    public bool Has(string type) => true;

    public IReadProcessor Read(string type) => throw new NotSupportedException();

    public IWriteProcessor Write(string type) => new Writer(verification);

    private sealed class Writer(Ver verification) : IWriteProcessor
    {
        public Ver Verify(object value, IMutationContext context) => verification;

        public object Write(object value) => value;
    }
}
