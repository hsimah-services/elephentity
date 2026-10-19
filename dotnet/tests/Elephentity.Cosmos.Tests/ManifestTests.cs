using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Tests;

public sealed class ManifestTests
{
    private static Dictionary<string, string> Fields(params string[] names) => names.ToDictionary(name => name, name => name);

    [Theory]
    [InlineData("id")]
    [InlineData("pk")]
    [InlineData("type")]
    [InlineData("_etag")]
    [InlineData("_u0")]
    public void AFieldCannotLiveInAPropertyTheContainerReserves(string property)
    {
        var failure = Assert.Throws<ArgumentException>(() =>
            new CosmosEntityLayout("Item", new Dictionary<string, string> { ["name"] = property }));

        Assert.Contains("reserves", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void TwoFieldsCannotShareAProperty()
    {
        var failure = Assert.Throws<ArgumentException>(() =>
            new CosmosEntityLayout("Item", new Dictionary<string, string> { ["a"] = "same", ["b"] = "same" }));

        Assert.Contains("claimed by both", failure.Message, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("has space")]
    [InlineData("a\"]")]
    [InlineData("1abc")]
    [InlineData("")]
    public void AnUnsafePropertyNameIsRefusedBeforeItCanReachAQuery(string property)
    {
        Assert.Throws<ArgumentException>(() =>
            new CosmosEntityLayout("Item", new Dictionary<string, string> { ["name"] = property }));
    }

    [Fact]
    public void AnEdgeReferenceCannotShareAFieldsProperty()
    {
        Assert.Throws<ArgumentException>(() => new CosmosEntityLayout(
            "Post",
            Fields("authorId"),
            null,
            new Dictionary<string, CosmosEdgePlacement> { ["author"] = new("Post", "author", "Author", RelationKind.ManyToOne, "authorId") }));
    }

    [Fact]
    public void AReferenceStoredOnTheTargetCannotShareThatTargetsProperty()
    {
        var failure = Assert.Throws<ArgumentException>(() => new CosmosStorageManifest(
            "p",
            new Dictionary<string, CosmosEntityLayout>
            {
                ["Post"] = new(
                    "Post",
                    Fields("title"),
                    null,
                    new Dictionary<string, CosmosEdgePlacement> { ["comments"] = new("Post", "comments", "Comment", RelationKind.OneToMany, "body") }),
                ["Comment"] = new("Comment", Fields("body")),
            }));

        Assert.Contains("also a field of Comment", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void ManifestsRefuseDanglingTargetsOrderingsAndUniqueKeys()
    {
        Assert.Throws<ArgumentException>(() => new CosmosStorageManifest(
            "p",
            new Dictionary<string, CosmosEntityLayout>
            {
                ["Post"] = new(
                    "Post",
                    Fields("title"),
                    null,
                    new Dictionary<string, CosmosEdgePlacement> { ["tags"] = new("Post", "tags", "Tag", RelationKind.ManyToMany) }),
            }));
        Assert.Throws<ArgumentException>(() => new CosmosStorageManifest(
            "p",
            new Dictionary<string, CosmosEntityLayout> { ["Post"] = new("Post", Fields("title")) },
            [new CosmosOrdering("Nope", [Order.Ascending("title")])]));
        Assert.Throws<ArgumentException>(() => new CosmosEntityLayout("Post", Fields("title"), [new CosmosUniqueKey("slug")]));
        Assert.Throws<ArgumentException>(() => new CosmosStorageManifest("  ", new Dictionary<string, CosmosEntityLayout>()));
    }

    [Fact]
    public void TheNumberOfUniqueSlotsIsTheMostAnyEntityHas()
    {
        var manifest = new CosmosStorageManifest(
            "p",
            new Dictionary<string, CosmosEntityLayout>
            {
                ["A"] = new("A", Fields("x", "y"), [new CosmosUniqueKey("x"), new CosmosUniqueKey("y")]),
                ["B"] = new("B", Fields("z"), [new CosmosUniqueKey("z")]),
                ["C"] = new("C", Fields("w")),
            });

        Assert.Equal(2, manifest.UniqueSlots);
        Assert.Equal(0, new CosmosStorageManifest("p", new Dictionary<string, CosmosEntityLayout>()).UniqueSlots);
    }

    [Fact]
    public void AJoinDocumentHasAStableIdForAPair()
    {
        var placement = Layouts.Blog().Edge("Post", "tags");

        Assert.Equal("__edge:Post.tags", placement.JoinType);
        Assert.Equal("__edge:Post.tags|p1|t2", placement.JoinId("p1", "t2"));
        Assert.True(placement.UsesJoinDocuments);
        Assert.False(placement.KeyIsLocal);
        Assert.True(Layouts.Blog().Edge("Post", "author").KeyIsLocal);
        Assert.False(Layouts.Blog().Edge("Post", "comments").KeyIsLocal);
    }

    [Fact]
    public void IdsAreTimeOrderedAndHaveNoDashes()
    {
        var ids = Enumerable.Range(0, 50).Select(_ => CosmosIds.New().ToString()).ToList();

        Assert.All(ids, id => Assert.Matches("^[0-9a-f]{32}$", id));
        Assert.Equal(50, ids.Distinct().Count());
        Assert.Equal('7', ids[0][12]);

        var before = CosmosIds.New().ToString();
        Thread.Sleep(5);

        Assert.True(string.CompareOrdinal(before, CosmosIds.New().ToString()) < 0, "a later id sorts after an earlier one");
    }

    [Fact]
    public void UniqueValuesCarryTheEntityAndFillersCarryTheId()
    {
        var sensitive = new CosmosUniqueKey("code");
        var insensitive = new CosmosUniqueKey("code", CaseSensitive: false);

        Assert.Equal("Item|=Ab", UniqueKeys.Value("Item", sensitive, "Ab", "i1"));
        Assert.Equal("Item|=AB", UniqueKeys.Value("Item", insensitive, "Ab", "i1"));
        Assert.Equal("Item|=7", UniqueKeys.Value("Item", sensitive, 7L, "i1"));
        Assert.Equal("Item|=true", UniqueKeys.Value("Item", sensitive, true, "i1"));
        Assert.Equal("Item|#i1", UniqueKeys.Value("Item", sensitive, null, "i1"));
        Assert.Equal("Item|#i1", UniqueKeys.Filler("Item", "i1"));
        Assert.NotEqual(UniqueKeys.Value("Item", sensitive, "#i1", "x"), UniqueKeys.Filler("Item", "i1"));
        Assert.NotEqual(UniqueKeys.Value("Item", sensitive, "x", "a"), UniqueKeys.Value("Post", sensitive, "x", "a"));
    }

    [Fact]
    public void TwoEntitiesOrderedByTheSamePropertyShareOneCompositeIndex()
    {
        var manifest = new CosmosStorageManifest(
            "p",
            new Dictionary<string, CosmosEntityLayout>
            {
                ["A"] = new("A", Fields("rank")),
                ["B"] = new("B", Fields("rank")),
            },
            [
                new CosmosOrdering("A", [Order.Ascending("rank")]),
                new CosmosOrdering("B", [Order.Ascending("rank")]),
                new CosmosOrdering("B", [Order.Descending("rank")]),
            ]);

        var indexes = CosmosInstaller.Properties(manifest).IndexingPolicy.CompositeIndexes;

        // Ascending is shared by A and B; descending is a different index.
        Assert.Equal(2, indexes.Count);
    }
}
