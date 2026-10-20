using Elephentity.Runtime.Boot;
using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Tests.Policy;
using Elephentity.Runtime.Tests.UnitOfWork;
using Elephentity.Runtime.Type;
using Elephentity.Runtime.Verification;
using Record = Elephentity.Runtime.Storage.Record;
using RuntimeFacade = Elephentity.Runtime.Gateway.Runtime;

namespace Elephentity.Runtime.Tests.Gateway;

public sealed class GatewayTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public void BootCheckNamesEveryMissingContractAtOnce()
    {
        var catalogue = new StubCatalogue();
        catalogue.ContractTypes.AddRange([typeof(IDisposable), typeof(IComparable), typeof(ICloneable)]);
        var services = new Services(typeof(IComparable));

        var failure = Assert.Throws<MissingImplementationsException>(() => new BootCheck(catalogue, services).Run());

        Assert.Contains("IDisposable", failure.Message, StringComparison.Ordinal);
        Assert.Contains("ICloneable", failure.Message, StringComparison.Ordinal);
        Assert.DoesNotContain("IComparable", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void BootCheckPassesWhenEverythingIsBound()
    {
        var catalogue = new StubCatalogue();
        catalogue.ContractTypes.Add(typeof(IDisposable));

        new BootCheck(catalogue, new Services(typeof(IDisposable))).Run();
    }

    [Fact]
    public async Task CreateCommitsAndReturnsTheNewRow()
    {
        var storage = new CreatingStorage();
        var runtime = Build(storage, new GatewayCatalogue());

        var result = await runtime.CreateAsync("Post", new Dictionary<string, object?> { ["title"] = "Hello" }, Token);

        Assert.Equal(EntityId.Of(1), result.Id);
        Assert.NotNull(result.Entity);
        Assert.Equal(["begin", "insert Post", "commit"], storage.Log);
    }

    [Fact]
    public async Task UpdateOfAMissingRowSaysSo()
    {
        var runtime = Build(new FakeStorage(), new GatewayCatalogue());

        var failure = await Assert.ThrowsAsync<EntityNotFoundException>(() =>
            runtime.UpdateAsync("Post", EntityId.Of(9), new Dictionary<string, object?>(), Token));

        Assert.Contains("no Post with id 9", failure.Message, StringComparison.Ordinal);
        Assert.Equal("Post", failure.Entity);
        Assert.Equal(EntityId.Of(9), failure.Id);
    }

    [Fact]
    public async Task ARunActionsCallWithNoActionsIsRefused()
    {
        var runtime = Build(new FakeStorage(), new GatewayCatalogue());

        await Assert.ThrowsAsync<ArgumentException>(() => runtime.RunActionsAsync("Post", EntityId.Of(1), [], Token));
    }

    private static RuntimeFacade Build(FakeStorage storage, GatewayCatalogue catalogue)
    {
        var reads = new ReadGate(catalogue, new AnonymousViewerProvider());
        var writes = new WriteGate(catalogue, new AnonymousViewerProvider());
        var units = new UnitOfWorkFactory(storage, catalogue, new NullProcessorRegistry());

        return new RuntimeFacade(storage, catalogue, units, reads, writes);
    }

    private sealed class Services(params System.Type[] bound) : IServiceProvider
    {
        public object? GetService(System.Type serviceType) => bound.Contains(serviceType) ? new object() : null;
    }

    private sealed class CreatingStorage : FakeStorage
    {
        public override Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default) =>
            Task.FromResult(new Page<Record>([]));

        public override Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default) =>
            Task.FromResult<Record?>(new Record(entity, id, new Dictionary<string, object?> { ["title"] = "Hello" }));
    }

    private sealed class GatewayCatalogue : StubCatalogue
    {
        public override IReadOnlyList<string> Entities() => ["Post"];

        public override IHydrator<object> Hydrator(string entity) => new RecordHydrator();

        public override IEntityVerifiers Verifiers(string entity) => new StubVerifiers([]);

        public override IEntitySideEffects SideEffects(string entity) => new RecordingSideEffects();
    }

    private sealed class RecordHydrator : IHydrator<object>
    {
        public object Hydrate(Record record, IEdgeLoader edges) => record;
    }
}
