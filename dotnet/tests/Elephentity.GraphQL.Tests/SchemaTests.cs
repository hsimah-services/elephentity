using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Schema;

namespace Elephentity.GraphQL.Tests;

public sealed class SchemaTests
{
    [Fact]
    public void TheSdlIsByteForByteWhatThePhpPackagePrintsForTheSameModel()
    {
        var expected = File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "book-author.graphql")).ReplaceLineEndings("\n");
        var actual = ElephentitySchemaPrinter.Print(ElephentitySchema.Build(BookModel.Manifest()));

        Assert.Equal(expected, actual);
    }

    [Fact]
    public void ACommittedSchemaThatMatchesChecksOutAndOneThatDriftedSaysWhere()
    {
        var schema = ElephentitySchema.Build(BookModel.Manifest());
        var sdl = ElephentitySchemaPrinter.Print(schema);

        Assert.Null(ElephentitySchemaPrinter.Check(schema, sdl));
        Assert.Null(ElephentitySchemaPrinter.Check(schema, sdl.ReplaceLineEndings("\r\n")));

        var drifted = ElephentitySchemaPrinter.Check(schema, sdl.Replace("title: String!", "title: String", StringComparison.Ordinal));

        Assert.NotNull(drifted);
        Assert.Contains("title: String!", drifted, StringComparison.Ordinal);
    }

    [Fact]
    public async Task EmptyDescriptionsAreEmptyStringsInIntrospectionNotNull()
    {
        var host = new Host();
        var result = await host.Run("{ author: __type(name: \"Author\") { description } book: __type(name: \"Book\") { description } connection: __type(name: \"BookToAuthorConnection\") { description } }");

        Assert.Equal(string.Empty, result.GetProperty("data").GetProperty("author").GetProperty("description").GetString());
        Assert.Equal("A book.", result.GetProperty("data").GetProperty("book").GetProperty("description").GetString());
        Assert.Equal(System.Text.Json.JsonValueKind.Null, result.GetProperty("data").GetProperty("connection").GetProperty("description").ValueKind);
    }

    [Fact]
    public void AQueryOnlyManifestHasNoMutationRoot()
    {
        var manifest = new GraphQLManifest(
            objects: new Dictionary<string, ObjectTypeEntry>
            {
                ["Note"] = new("Note", "Note", new Dictionary<string, FieldEntry> { ["body"] = new("body", new("String"), source => source) }, source => source is string, interfaces: []),
            });

        var schema = ElephentitySchema.Build(manifest);

        Assert.Null(schema.Mutation);
        Assert.StartsWith("schema {\n  query: RootQuery\n}", ElephentitySchemaPrinter.Print(schema), StringComparison.Ordinal);
    }

    [Fact]
    public void TwoEdgesToTheSameTypeAreTwoFieldsOfOneConnectionType()
    {
        var manifest = BookModel.Manifest();
        var book = manifest.Objects["Book"];
        var both = new GraphQLManifest(
            objects: new Dictionary<string, ObjectTypeEntry>(manifest.Objects)
            {
                ["Book"] = new(
                    book.Name,
                    book.Entity,
                    book.Fields,
                    book.IsInstance,
                    new Dictionary<string, ConnectionEntry>(book.Connections)
                    {
                        ["editors"] = new("editors", "Book", "Author", "editors", source => ((BookModel.Book)source).Authors()),
                    },
                    book.Description),
            },
            enums: manifest.Enums,
            roots: manifest.Roots);

        var sdl = ElephentitySchemaPrinter.Print(ElephentitySchema.Build(both));

        Assert.Contains("authors(after: String, before: String, first: Int, last: Int): BookToAuthorConnection", sdl, StringComparison.Ordinal);
        Assert.Contains("editors(after: String, before: String, first: Int, last: Int): BookToAuthorConnection", sdl, StringComparison.Ordinal);
        Assert.Equal(2, sdl.Split("type BookToAuthorConnection {").Length);
    }

    [Fact]
    public void ATypeTheManifestDefinesTwiceIsReportedByName()
    {
        var manifest = BookModel.Manifest();
        var clash = new GraphQLManifest(
            objects: manifest.Objects,
            enums: new Dictionary<string, EnumTypeEntry>(manifest.Enums) { ["Book"] = new("Book", new Dictionary<string, string> { ["A"] = "a" }) },
            roots: manifest.Roots);

        var failure = Assert.Throws<InvalidOperationException>(() => ElephentitySchema.Build(clash));

        Assert.Contains("Two GraphQL types are named Book", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void ADescriptionThatCannotBeABlockStringIsQuotedAndAMultiLineOneIsABlock()
    {
        var manifest = new GraphQLManifest(
            objects: new Dictionary<string, ObjectTypeEntry>
            {
                ["Note"] = new("Note", "Note", new Dictionary<string, FieldEntry>
                {
                    ["body"] = new("body", new("String"), source => source, "First line.\nSecond \"line\"."),
                    ["tab"] = new("tab", new("String"), source => source, "has\ttab\u0001control"),
                }, source => source is string, null, "A note.", []),
            });

        var sdl = ElephentitySchemaPrinter.Print(ElephentitySchema.Build(manifest));

        Assert.Contains("  \"\"\"\n  First line.\n  Second \"line\".\n  \"\"\"\n  body: String", sdl, StringComparison.Ordinal);
        Assert.Contains("  \"has\\ttab\\u0001control\"\n  tab: String", sdl, StringComparison.Ordinal);
    }

    [Fact]
    public void AFieldThatGivesBothReadersOrNeitherIsReportedWhenTheSchemaIsBuilt()
    {
        foreach (var field in new[]
        {
            new FieldEntry("body", new("String"), null),
            new FieldEntry("body", new("String"), source => source, ReadAsync: (source, token) => ValueTask.FromResult<object?>(source)),
        })
        {
            var manifest = new GraphQLManifest(
                objects: new Dictionary<string, ObjectTypeEntry>
                {
                    ["Note"] = new("Note", "Note", new Dictionary<string, FieldEntry> { ["body"] = field }, source => source is string, interfaces: []),
                });

            var failure = Assert.Throws<InvalidOperationException>(() => ElephentitySchema.Build(manifest));

            Assert.Contains("Note.body must give exactly one of Read and ReadAsync", failure.Message, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void TwoConnectionsThatMakeTheSameTypeNameFromDifferentTargetsAreReportedWithBothTargets()
    {
        // "AToB" + "To" + "C" and "A" + "To" + "BToC" are both AToBToCConnection.
        ObjectTypeEntry Type(string name, string connectionTo, string field) => new(
            name,
            name,
            new Dictionary<string, FieldEntry> { ["id"] = new("id", new("ID", NonNull: true), source => source) },
            source => source is string,
            new Dictionary<string, ConnectionEntry> { [field] = new(field, name, connectionTo, field, source => throw new NotSupportedException()) },
            interfaces: []);

        var manifest = new GraphQLManifest(
            objects: new Dictionary<string, ObjectTypeEntry>
            {
                ["AToB"] = Type("AToB", "C", "first"),
                ["A"] = Type("A", "BToC", "second"),
                ["C"] = new("C", "C", new Dictionary<string, FieldEntry> { ["id"] = new("id", new("ID", NonNull: true), source => source) }, source => source is string, interfaces: []),
                ["BToC"] = new("BToC", "BToC", new Dictionary<string, FieldEntry> { ["id"] = new("id", new("ID", NonNull: true), source => source) }, source => source is string, interfaces: []),
            });

        var failure = Assert.Throws<InvalidOperationException>(() => ElephentitySchema.Build(manifest));

        Assert.Contains("targets both", failure.Message, StringComparison.Ordinal);
        Assert.Contains("\"C\"", failure.Message, StringComparison.Ordinal);
        Assert.Contains("\"BToC\"", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task APublishedCollectionQueryWithNoArgumentsGetsNoWhereInput()
    {
        var manifest = BookModel.Manifest();
        var withQuery = new GraphQLManifest(
            manifest.Objects,
            manifest.Enums,
            manifest.Mutations,
            manifest.Roots,
            new Dictionary<string, QueryFieldEntry>(manifest.Queries)
            {
                ["recentBooks"] = new("recentBooks", "Book", true, "Book", "recent"),
            });

        var schema = ElephentitySchema.Build(withQuery);
        var sdl = ElephentitySchemaPrinter.Print(schema);

        // An input object with no fields is not valid GraphQL, so there is no where; the connection is still named by the field.
        Assert.Contains("recentBooks(after: String, before: String, first: Int, last: Int): RootQueryToRecentBooksConnection", sdl, StringComparison.Ordinal);
        Assert.DoesNotContain("RootQueryToRecentBooksConnectionWhereArgs", sdl, StringComparison.Ordinal);

        var host = new Host(manifest: withQuery);
        var result = await host.Run("{ __type(name: \"RootQueryToRecentBooksConnection\") { name } }");

        Assert.Equal("RootQueryToRecentBooksConnection", result.GetProperty("data").GetProperty("__type").GetProperty("name").GetString());
    }
}
