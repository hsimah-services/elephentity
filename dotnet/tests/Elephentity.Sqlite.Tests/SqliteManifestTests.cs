using Elephentity.Runtime.Storage;
using Elephentity.Sqlite.Manifest;

namespace Elephentity.Sqlite.Tests;

public sealed class SqliteManifestTests
{
    [Theory]
    [InlineData("has space")]
    [InlineData("a\"b")]
    [InlineData("1abc")]
    [InlineData("")]
    [InlineData("a;b")]
    public void AnUnsafeNameIsRefusedBeforeItCanReachAStatement(string name)
    {
        Assert.Throws<ArgumentException>(() => new SqliteTable(name, [new SqliteColumn("id", "INTEGER")], primaryKey: "id"));
        Assert.Throws<ArgumentException>(() => new SqliteTable("t", [new SqliteColumn(name, "INTEGER")], primaryKey: string.Empty));
    }

    [Fact]
    public void ATableRefusesADuplicateColumnAnIndexOnNothingAndAMissingPrimaryKey()
    {
        Assert.Throws<ArgumentException>(() => new SqliteTable("t", [new SqliteColumn("a", "TEXT"), new SqliteColumn("a", "TEXT")], primaryKey: string.Empty));
        Assert.Throws<ArgumentException>(() => new SqliteTable("t", [new SqliteColumn("a", "TEXT")], [new SqliteIndex("i", ["b"])], string.Empty));
        Assert.Throws<ArgumentException>(() => new SqliteTable("t", [new SqliteColumn("a", "TEXT")]));
    }

    [Fact]
    public void APlacementMustPointAtTablesTheManifestHas()
    {
        var table = new SqliteTable("t", [new SqliteColumn("id", "INTEGER", false, true)]);

        Assert.Throws<ArgumentException>(() => new SqliteStorageManifest(
            new Dictionary<string, SqliteTable> { ["T"] = table },
            new Dictionary<string, SqliteEdgePlacement> { ["T.e"] = new("T", "e", "T", RelationKind.ManyToOne, "t", "e_id", null, "gone") }));
        Assert.Throws<ArgumentException>(() => new SqliteStorageManifest(
            new Dictionary<string, SqliteTable> { ["T"] = table },
            new Dictionary<string, SqliteEdgePlacement> { ["wrong"] = new("T", "e", "T", RelationKind.ManyToOne, "t", "e_id", null, "t") }));
    }

    [Fact]
    public void ColumnsMustBelongToATableThatHasThem()
    {
        var table = new SqliteTable("t", [new SqliteColumn("id", "INTEGER", false, true)]);

        Assert.Throws<ArgumentException>(() => new SqliteStorageManifest(
            new Dictionary<string, SqliteTable> { ["T"] = table },
            columns: new Dictionary<string, IReadOnlyDictionary<string, string>> { ["T"] = new Dictionary<string, string> { ["name"] = "name" } }));
        Assert.Throws<ArgumentException>(() => new SqliteStorageManifest(
            new Dictionary<string, SqliteTable> { ["T"] = table },
            columns: new Dictionary<string, IReadOnlyDictionary<string, string>> { ["U"] = new Dictionary<string, string>() }));
    }

    [Fact]
    public void EveryTableListsEntityAndJoinTablesByName()
    {
        Assert.Equal(
            ["author", "comment", "post", "post_tags", "profile", "tag"],
            Schema.Blog().EveryTable().Select(table => table.Name));
    }

    [Fact]
    public void AColumnMapsBothWaysAndAnUnmappedNameIsAlreadyInDatabaseForm()
    {
        var manifest = Schema.Blog();

        Assert.Equal("kind", manifest.Column("Comment", "type"));
        Assert.Equal("type", manifest.Field("Comment", "kind"));
        Assert.Equal("post_id", manifest.Column("Comment", "post_id"));
        Assert.Equal("post_id", manifest.Field("Comment", "post_id"));
    }

    [Fact]
    public void APrefixMovesTablesIndexesAndPlacementsTogetherAndNothingElse()
    {
        var manifest = Schema.Blog();
        var prefixed = manifest.WithPrefix("app_");

        Assert.Same(manifest, manifest.WithPrefix(string.Empty));
        Assert.Equal("app_post", prefixed.Table("Post").Name);
        Assert.Equal("app_post_slug_uniq", prefixed.Table("Post").Indexes.Single(index => index.Unique).Name);
        Assert.Equal("app_post_tags", prefixed.Placement("Post", "tags").Table);
        Assert.Equal("app_tag", prefixed.Placement("Post", "tags").TargetTable);
        Assert.Equal("post_id", prefixed.Placement("Post", "tags").LocalColumn);
        Assert.Contains("app_post_tags", prefixed.JoinTables.Keys);
        Assert.Equal(manifest.Columns, prefixed.Columns);
        Assert.Throws<ArgumentException>(() => manifest.WithPrefix("no-good"));
    }

    [Fact]
    public void AStatementRendersQuotedNamesWithThePrefixAndLeavesLiteralTextAlone()
    {
        var statement = new SqliteStatement([SqliteStatementPart.Sql("CREATE INDEX "), SqliteStatementPart.Name("i"), SqliteStatementPart.Sql(" ON "), SqliteStatementPart.Name("t"), SqliteStatementPart.Sql(" (\"c\") -- 'x'")]);

        Assert.Equal("CREATE INDEX \"i\" ON \"t\" (\"c\") -- 'x'", statement.Render(string.Empty));
        Assert.Equal("CREATE INDEX \"p_i\" ON \"p_t\" (\"c\") -- 'x'", statement.Render("p_"));
        Assert.Throws<ArgumentException>(() => statement.Render("a-"));
    }

    [Fact]
    public void ARelationKnowsWhereItsKeyLives()
    {
        var manifest = Schema.Blog();

        Assert.True(manifest.Placement("Post", "author").KeyIsLocal);
        Assert.True(manifest.Placement("Author", "profile").KeyIsLocal);
        Assert.False(manifest.Placement("Post", "comments").KeyIsLocal);
        Assert.False(manifest.Placement("Post", "tags").KeyIsLocal);
        Assert.True(manifest.Placement("Post", "tags").UsesJoinTable);
        Assert.Throws<InvalidOperationException>(() => manifest.Placement("Post", "nope"));
        Assert.Throws<InvalidOperationException>(() => manifest.Table("Nope"));
    }
}
