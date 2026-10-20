using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Memory;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.SideEffect;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Testing;
using Elephentity.Runtime.Type;
using Elephentity.Runtime.Verification;
using Record = Elephentity.Runtime.Storage.Record;
using RuntimeFacade = Elephentity.Runtime.Gateway.Runtime;

namespace Elephentity.Runtime.Memory.Tests;

/// <summary>
/// The runtime, the unit of work, policies, side effects and a real adaptor, together: the PHP
/// <c>MutationLifecycleTest</c>, which is where "everything a commit does" is proven to happen once, in order.
/// </summary>
public sealed class MutationLifecycleTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task ActionsAndSideEffectsShareOneCommitAndHiddenResultsAreSuccessful()
    {
        using var storage = await SeededStorage();
        var writeCalls = 0;
        var writes = new WritePolicy((original, context) =>
        {
            writeCalls++;
            Assert.NotNull(original);
            Assert.Empty(context.Mutation!.Changes());
            Assert.Equal(["rename", "rename"], context.Actions.Select(call => call.Name));
            Assert.Equal("original", context.Mutation.Original("title"));

            return PolicyDecision.Allow();
        });
        var readCalls = 0;
        var reads = new ReadPolicy(entity =>
        {
            readCalls++;
            Assert.Equal("second+pre", ((LifecycleEntity)entity).Title);

            return PolicyDecision.Deny("hidden");
        });
        var seenAfterCommit = new List<object?>();

        var runtime = Build(
            storage,
            async (phase, context) =>
            {
                if (phase == SideEffectPhase.PreCommit)
                {
                    Assert.Equal("original", (await storage.GetAsync("Post", EntityId.Of(1), Token))?.Value("title"));
                    Assert.Equal("second", context.Pending("title"));
                    context.Assign("title", "second+pre");
                    context.Edge("related").Add(EntityId.Of(2));

                    return;
                }

                seenAfterCommit.Add((await storage.GetAsync("Post", EntityId.Of(1), Token))?.Value("title"));

                throw new InvalidOperationException("external service failed");
            },
            writes,
            reads);

        var result = await runtime.RunActionsAsync(
            "Post",
            EntityId.Of(1),
            [new ActionCall("rename", new Dictionary<string, object?> { ["title"] = "first" }), new ActionCall("rename", new Dictionary<string, object?> { ["title"] = "second" })],
            Token);

        Assert.Null(result.Entity);
        Assert.Equal(["second+pre"], seenAfterCommit);
        Assert.Equal("second+pre", (await storage.GetAsync("Post", result.Id, Token))?.Value("title"));
        Assert.Equal(2, writeCalls);
        Assert.Equal(1, readCalls);

        var linked = await storage.QueryAsync(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "related", EntityId.Of(2))), Token);

        Assert.Equal([EntityId.Of(1)], linked.Items.Select(record => record.Id));
    }

    [Fact]
    public async Task AnActionFailureDiscardsEarlierActions()
    {
        using var storage = await SeededStorage();
        var runtime = Build(storage, (_, _) => throw new InvalidOperationException("Side effects must not run."));

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => runtime.RunActionsAsync(
            "Post",
            EntityId.Of(1),
            [new ActionCall("rename", new Dictionary<string, object?> { ["title"] = "first" }), new ActionCall("fail", new Dictionary<string, object?>())],
            Token));

        Assert.Equal("action failed", failure.Message);
        Assert.Equal("original", (await storage.GetAsync("Post", EntityId.Of(1), Token))?.Value("title"));
    }

    [Fact]
    public async Task CreatePolicySeesEmptyBufferAndSideEffectsCanSupplyRequiredFields()
    {
        using var storage = new MemoryAdaptor();
        var writeCalls = 0;
        var writes = new WritePolicy((original, context) =>
        {
            writeCalls++;
            Assert.Null(original);
            Assert.Empty(context.Mutation!.Changes());
            Assert.Equal("input", context.Arguments["title"]);

            return PolicyDecision.Allow();
        });
        var runtime = Build(
            storage,
            (phase, context) =>
            {
                if (phase == SideEffectPhase.PreCommit)
                {
                    Assert.IsType<PendingId>(context.Id);
                    context.Assign("title", "from side effect");
                }
                else
                {
                    Assert.IsType<EntityId>(context.Id);
                }

                return ValueTask.CompletedTask;
            },
            writes);

        var result = await runtime.CreateAsync("Post", new Dictionary<string, object?> { ["title"] = "input" }, Token);

        Assert.Equal("from side effect", Assert.IsType<LifecycleEntity>(result.Entity).Title);
        Assert.Equal(1, writeCalls);
    }

    [Fact]
    public async Task AnEmptyCreateCanBeCompletedByPreCommitSideEffects()
    {
        using var storage = new MemoryAdaptor();
        var runtime = Build(
            storage,
            (phase, context) =>
            {
                if (phase == SideEffectPhase.PreCommit)
                {
                    context.Assign("title", "default");
                }

                return ValueTask.CompletedTask;
            });

        var result = await runtime.CreateAsync("Post", new Dictionary<string, object?>(), Token);

        Assert.Equal("default", (await storage.GetAsync("Post", result.Id, Token))?.Value("title"));
    }

    [Fact]
    public async Task SideEffectChangesAreVerifiedBeforeStorage()
    {
        using var storage = new MemoryAdaptor();
        var runtime = Build(
            storage,
            (phase, context) =>
            {
                if (phase == SideEffectPhase.PreCommit)
                {
                    context.Assign("title", null);
                }

                return ValueTask.CompletedTask;
            });

        await Assert.ThrowsAsync<CommitRejectedException>(() =>
            runtime.CreateAsync("Post", new Dictionary<string, object?> { ["title"] = "valid" }, Token));

        Assert.Null(await storage.GetAsync("Post", EntityId.Of(1), Token));
    }

    [Fact]
    public async Task ADeleteRemovesTheRowAndAnUpdateOfAMissingRowIsRefused()
    {
        using var storage = await SeededStorage();
        var runtime = Build(storage, (_, _) => ValueTask.CompletedTask);

        await runtime.DeleteAsync("Post", EntityId.Of(1), Token);

        Assert.Null(await storage.GetAsync("Post", EntityId.Of(1), Token));
        await Assert.ThrowsAsync<EntityNotFoundException>(() =>
            runtime.UpdateAsync("Post", EntityId.Of(1), new Dictionary<string, object?> { ["title"] = "x" }, Token));
    }

    private static async Task<MemoryAdaptor> SeededStorage()
    {
        var storage = new MemoryAdaptor();

        await storage.WriteAsync(
            new WriteBatch(new Insert("Post", new PendingId("Post"), new Dictionary<string, object?> { ["title"] = "original" })),
            Token);

        return storage;
    }

    private static RuntimeFacade Build(
        MemoryAdaptor storage,
        Func<SideEffectPhase, IMutableMutationContext, ValueTask> handler,
        IEntityWritePolicies? writes = null,
        IEntityReadPolicies? reads = null)
    {
        var catalogue = new LifecycleCatalogue(handler, writes, reads);
        var viewers = new AnonymousViewerProvider();

        return new RuntimeFacade(
            storage,
            catalogue,
            new UnitOfWorkFactory(storage, catalogue, new NullProcessorRegistry()),
            new ReadGate(catalogue, viewers),
            new WriteGate(catalogue, viewers));
    }

    private sealed record LifecycleEntity(string Title);

    private sealed class LifecycleCatalogue(
        Func<SideEffectPhase, IMutableMutationContext, ValueTask> handler,
        IEntityWritePolicies? writes,
        IEntityReadPolicies? reads) : StubEntityCatalogue
    {
        public override IReadOnlyList<string> Entities() => ["Post"];

        public override IReadOnlyList<string> FieldNames(string entity) => ["title"];

        public override IReadOnlyList<string> RequiredFields(string entity) => ["title"];

        public override IEntityWritePolicies WritePolicies(string entity) => writes ?? new NoPolicies();

        public override IEntityReadPolicies ReadPolicies(string entity) => reads ?? new NoPolicies();

        public override IHydrator<object> Hydrator(string entity) => new LifecycleHydrator();

        public override IEntitySideEffects SideEffects(string entity) => new Effects(handler);

        public override IReadOnlyDictionary<string, object?> ValuesOf(string entity, object instance) =>
            new Dictionary<string, object?> { ["title"] = ((LifecycleEntity)instance).Title };

        public override ValueTask RunActionAsync(
            string entity,
            IMutableMutationContext mutation,
            ActionCall action,
            CancellationToken cancellationToken = default)
        {
            switch (action.Name)
            {
                case "rename":
                    mutation.Assign("title", action.Arguments["title"]);

                    return ValueTask.CompletedTask;
                case "fail":
                    throw new InvalidOperationException("action failed");
                default:
                    throw new InvalidOperationException($"Unknown action {action.Name}.");
            }
        }
    }

    private sealed class LifecycleHydrator : IHydrator<object>
    {
        public object Hydrate(Record record, IEdgeLoader edges) => new LifecycleEntity(Convert.ToString(record.Value("title"), System.Globalization.CultureInfo.InvariantCulture) ?? string.Empty);
    }

    private sealed class Effects(Func<SideEffectPhase, IMutableMutationContext, ValueTask> handler) : IEntitySideEffects
    {
        public IEnumerable<Func<CancellationToken, ValueTask>> Handlers(
            SideEffectPhase phase,
            SideEffectEvent sideEffectEvent,
            IMutableMutationContext context)
        {
            yield return _ => handler(phase, context);
        }
    }

    private sealed class WritePolicy(Func<object?, IWriteContext, PolicyDecision> decide) : IEntityWritePolicies
    {
        public ValueTask<PolicyDecision> DecideAsync(object? entity, IWriteContext context, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(decide(entity, context));

        public bool IsEmpty() => false;
    }

    private sealed class ReadPolicy(Func<object, PolicyDecision> decide) : IEntityReadPolicies
    {
        public ValueTask<PolicyDecision> DecideAsync(object entity, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(decide(entity));

        public bool IsEmpty() => false;
    }
}
