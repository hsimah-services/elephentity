using Elephentity.GraphQL;
using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Schema;

namespace Golden.GraphQL.Tests;

/// <summary>
/// The proof that the .NET stack is the PHP stack: the schema <c>Elephentity.GraphQL</c> assembles from each generated manifest prints
/// the same SDL, byte for byte, as the PHP stack's schema for the same spec (<c>tools/refresh-parity</c> makes the files). Any
/// difference fails.
/// </summary>
public sealed class ParityTests
{
    public static TheoryData<string> Fixtures { get; } = ["book", "relations", "clog"];

    private static GraphQLManifest Manifest(string fixture) => fixture switch
    {
        "book" => Golden.Book.GraphQL.GraphQLManifestFactory.Create(),
        "relations" => Golden.Relations.GraphQL.GraphQLManifestFactory.Create(),
        "clog" => Golden.Clog.GraphQL.GraphQLManifestFactory.Create(),
        _ => throw new ArgumentOutOfRangeException(nameof(fixture)),
    };

    [Theory]
    [MemberData(nameof(Fixtures))]
    public void TheSdlIsByteForByteTheSdlThePhpStackPrints(string fixture)
    {
        var expected = File.ReadAllText(Path.Combine(AppContext.BaseDirectory, "parity", fixture + ".graphql")).ReplaceLineEndings("\n");
        var actual = ElephentitySchemaPrinter.Print(ElephentitySchema.Build(Manifest(fixture)));

        Assert.Equal(expected, actual);
    }
}
