using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Tests;

/// <summary>Manifests the tests share: a blog with every edge shape.</summary>
internal static class Layouts
{
    public const string Partition = "blog";

    /// <summary>
    /// Post.author is many-to-one (the reference is Post's own <c>authorId</c>), Post.comments is one-to-many (each
    /// Comment carries <c>postId</c>), Post.tags is many-to-many (join documents).
    /// </summary>
    public static CosmosStorageManifest Blog(string partition = Partition, string container = "blog") => new(
        partition,
        new Dictionary<string, CosmosEntityLayout>
        {
            ["Post"] = new(
                "Post",
                new Dictionary<string, string> { ["title"] = "title", ["slug"] = "slug", ["rank"] = "rank", ["views"] = "views" },
                [new CosmosUniqueKey("slug", CaseSensitive: false)],
                new Dictionary<string, CosmosEdgePlacement>
                {
                    ["author"] = new("Post", "author", "Author", RelationKind.ManyToOne, "authorId"),
                    ["comments"] = new("Post", "comments", "Comment", RelationKind.OneToMany, "postId"),
                    ["tags"] = new("Post", "tags", "Tag", RelationKind.ManyToMany),
                }),
            ["Author"] = new("Author", new Dictionary<string, string> { ["name"] = "name" }),
            ["Comment"] = new("Comment", new Dictionary<string, string> { ["body"] = "body", ["type"] = "kind" }),
            ["Tag"] = new("Tag", new Dictionary<string, string> { ["label"] = "label" }),
        },
        [new CosmosOrdering("Post", [Order.Descending("rank")])],
        container);

    /// <summary>The same entities, with the names the conformance suite uses.</summary>
    public static CosmosStorageManifest Conformance(string partition = "conformance") => new(
        partition,
        new Dictionary<string, CosmosEntityLayout>
        {
            ["ConformanceEntity"] = new(
                "ConformanceEntity",
                new Dictionary<string, string> { ["name"] = "name", ["rank"] = "rank" },
                null,
                new Dictionary<string, CosmosEdgePlacement>
                {
                    ["related"] = new("ConformanceEntity", "related", "ConformanceRelated", RelationKind.ManyToMany),
                }),
            ["ConformanceRelated"] = new("ConformanceRelated", new Dictionary<string, string> { ["name"] = "name" }),
        },
        [new CosmosOrdering("ConformanceEntity", [Order.Ascending("rank")]), new CosmosOrdering("ConformanceEntity", [Order.Descending("rank")])],
        "conformance");
}
