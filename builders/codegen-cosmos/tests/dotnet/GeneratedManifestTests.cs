using Elephentity.Cosmos;
using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Testing;
using Elephentity.Runtime.Testing.Xunit;

namespace Golden.Cosmos.Tests;

/// <summary>
/// The conformance suite, on the emulator, over each relation shape's generated manifest. The shared-target case links a
/// second target to an owner that already has one, which only a many-to-many allows, so only that shape turns it on.
/// </summary>
public sealed class OneToOneConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target");

    protected override IStorageAdaptor CreateAdaptor() => Emulator.Adaptor(Golden.OneToOne.CosmosStorageManifestFactory.Create, "one-to-one");
}

public sealed class ManyToOneConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target");

    protected override IStorageAdaptor CreateAdaptor() => Emulator.Adaptor(Golden.ManyToOne.CosmosStorageManifestFactory.Create, "many-to-one");
}

public sealed class OneToManyConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target");

    protected override IStorageAdaptor CreateAdaptor() => Emulator.Adaptor(Golden.OneToMany.CosmosStorageManifestFactory.Create, "one-to-many");
}

public sealed class ManyToManyConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target", SharedTargets: true);

    protected override IStorageAdaptor CreateAdaptor() => Emulator.Adaptor(Golden.ManyToMany.CosmosStorageManifestFactory.Create, "many-to-many");
}

/// <summary>What the layouts say, with no server: they load, they mean what the spec meant, and they install.</summary>
public sealed class GeneratedManifestTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    public static TheoryData<string> Fixtures { get; } = ["clog", "relations", "one-to-one", "many-to-one", "one-to-many", "many-to-many"];

    private static CosmosStorageManifest Load(string fixture) => fixture switch
    {
        "clog" => Golden.Clog.CosmosStorageManifestFactory.Create(),
        "relations" => Golden.Relations.CosmosStorageManifestFactory.Create(),
        "one-to-one" => Golden.OneToOne.CosmosStorageManifestFactory.Create(),
        "many-to-one" => Golden.ManyToOne.CosmosStorageManifestFactory.Create(),
        "one-to-many" => Golden.OneToMany.CosmosStorageManifestFactory.Create(),
        "many-to-many" => Golden.ManyToMany.CosmosStorageManifestFactory.Create(),
        _ => throw new ArgumentOutOfRangeException(nameof(fixture)),
    };

    [Theory]
    [MemberData(nameof(Fixtures))]
    public void EveryManifestLoadsWithTheDefaultContainerAndOnePartition(string fixture)
    {
        var manifest = Load(fixture);

        Assert.Equal(CosmosStorageManifest.DefaultContainer, manifest.Container);
        Assert.NotEmpty(manifest.PartitionKey);
        Assert.NotEmpty(manifest.Entities);
    }

    [Fact]
    public void TheContainerIsTheOnlyThingADeploymentChooses()
    {
        Assert.Equal("app", Golden.Relations.CosmosStorageManifestFactory.Create("app").Container);
    }

    [Fact]
    public void EdgeReferencesLiveWhereTheRelationPutsThem()
    {
        var manifest = Golden.Relations.CosmosStorageManifestFactory.Create();

        Assert.Equal(("authorId", RelationKind.ManyToOne), (manifest.Edge("Comment", "author").Property, manifest.Edge("Comment", "author").Relation));
        Assert.Equal(("postId", RelationKind.OneToMany), (manifest.Edge("Post", "comments").Property, manifest.Edge("Post", "comments").Relation));
        Assert.True(manifest.Edge("Post", "tags").UsesJoinDocuments);
        Assert.True(manifest.Edge("Post", "related").UsesJoinDocuments);
        Assert.Equal("featuredAuthorId", manifest.Edge("Post", "featuredAuthor").Property);
    }

    [Fact]
    public void UniqueFieldsBecomeKeysAndEveryIndexedFieldGetsACompositeIndex()
    {
        var manifest = Golden.Relations.CosmosStorageManifestFactory.Create();

        Assert.Equal(["postId"], manifest.Entity("Post").UniqueKeys.Select(key => key.Field));
        Assert.Equal(["label"], manifest.Entity("Tag").UniqueKeys.Select(key => key.Field));
        Assert.Equal(1, manifest.UniqueSlots);
        Assert.Contains(manifest.Orderings, ordering => ordering.Entity == "Post" && ordering.Terms.Any(term => term.Field == "title"));
    }

    [Theory]
    [MemberData(nameof(Fixtures))]
    public async Task EveryManifestInstallsIntoAnEmptyContainer(string fixture)
    {
        // The relation shapes share their conformance container (one install per container), so this is the install for
        // clog and relations and a read for the rest; reading nothing from a fresh adaptor proves the layout stood up.
        var adaptor = Emulator.Adaptor(name => name switch
        {
            _ => fixture switch
            {
                "clog" => Golden.Clog.CosmosStorageManifestFactory.Create(name),
                "relations" => Golden.Relations.CosmosStorageManifestFactory.Create(name),
                "one-to-one" => Golden.OneToOne.CosmosStorageManifestFactory.Create(name),
                "many-to-one" => Golden.ManyToOne.CosmosStorageManifestFactory.Create(name),
                "one-to-many" => Golden.OneToMany.CosmosStorageManifestFactory.Create(name),
                _ => Golden.ManyToMany.CosmosStorageManifestFactory.Create(name),
            },
        }, fixture);

        var entity = Load(fixture).Entities.Keys.First();

        Assert.Equal(0, await adaptor.CountAsync(Criteria.For(entity).Unbounded(), Token));
    }
}
