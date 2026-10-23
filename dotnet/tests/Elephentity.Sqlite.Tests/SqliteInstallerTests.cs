using Elephentity.Sqlite.Manifest;
using Microsoft.Data.Sqlite;

namespace Elephentity.Sqlite.Tests;

public sealed class SqliteInstallerTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private static async Task<List<string>> ObjectsAsync(SqliteConnection connection)
    {
        await using var command = connection.CreateCommand();

        command.CommandText = "SELECT name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name";

        var names = new List<string>();
        await using var reader = await command.ExecuteReaderAsync(Token);

        while (await reader.ReadAsync(Token))
        {
            names.Add(reader.GetString(0));
        }

        return names;
    }

    [Fact]
    public async Task ItCreatesEveryTableAndIndexAndEnablesForeignKeys()
    {
        await using var connection = await Schema.InstalledAsync(Schema.Blog());

        Assert.Equal(
            [
                "author", "author_profile_id_uniq", "comment", "comment_post_id_idx", "post", "post_rank_idx", "post_slug_uniq",
                "post_tags", "post_tags_pair_uniq", "post_tags_tag_id_idx", "profile", "tag",
            ],
            await ObjectsAsync(connection));
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "PRAGMA foreign_keys"));
    }

    [Fact]
    public async Task AnAdditionalPrefixGoesInFrontOfEveryTableAndIndex()
    {
        await using var connection = await Schema.InstalledAsync(Schema.Blog(), "app_");

        Assert.All(await ObjectsAsync(connection), name => Assert.StartsWith("app_", name, StringComparison.Ordinal));

        // And the same prefix on the manifest is what the adaptor then reads and writes.
        using var adaptor = new SqliteAdaptor(connection, Schema.Blog().WithPrefix("app_"));
        var pending = new Elephentity.Runtime.Identity.PendingId("Tag");
        var result = await adaptor.WriteAsync(
            new Elephentity.Runtime.Storage.Write.WriteBatch(
                new Elephentity.Runtime.Storage.Write.Insert("Tag", pending, new Dictionary<string, object?> { ["label"] = "x" })),
            Token);

        Assert.True(result.WasAssigned(pending));
        Assert.Equal(1L, await Schema.ScalarAsync(connection, "SELECT COUNT(*) FROM app_tag"));
    }

    [Theory]
    [InlineData("app-")]
    [InlineData("1app")]
    [InlineData("a\"; DROP TABLE x;--")]
    public async Task APrefixThatIsNotAnIdentifierIsRefusedBeforeAnythingRuns(string prefix)
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");

        await Assert.ThrowsAsync<ArgumentException>(() => SqliteInstaller.InstallAsync(connection, Schema.Blog(), prefix, Token));
        Assert.Equal(System.Data.ConnectionState.Closed, connection.State);
    }

    [Fact]
    public async Task ExistingTablesFailTheInstallAndLeaveTheDatabaseAsItWas()
    {
        await using var connection = await Schema.InstalledAsync(Schema.Blog());

        await using (var drop = connection.CreateCommand())
        {
            drop.CommandText = "DROP TABLE comment";
            await drop.ExecuteNonQueryAsync(Token);
        }

        var before = await ObjectsAsync(connection);

        // Everything but `comment` exists already, so the first statement fails; nothing is created or dropped.
        await Assert.ThrowsAsync<SqliteException>(() => SqliteInstaller.InstallAsync(connection, Schema.Blog(), cancellationToken: Token));
        Assert.Equal(before, await ObjectsAsync(connection));
    }

    [Fact]
    public async Task AFailureHalfWayRollsBackTheTablesAlreadyCreated()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");
        var manifest = new SqliteStorageManifest(
            new Dictionary<string, SqliteTable>(),
            install:
            [
                new([SqliteStatementPart.Sql("CREATE TABLE "), SqliteStatementPart.Name("first"), SqliteStatementPart.Sql(" (\"id\" INTEGER PRIMARY KEY)")]),
                new([SqliteStatementPart.Sql("THIS IS NOT SQL")]),
            ]);

        await Assert.ThrowsAsync<SqliteException>(() => SqliteInstaller.InstallAsync(connection, manifest, cancellationToken: Token));
        Assert.Empty(await ObjectsAsync(connection));
    }

    [Fact]
    public async Task ItRefusesToRunInsideATransactionTheCallerOpened()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");

        await connection.OpenAsync(Token);

        await using (var begin = connection.CreateCommand())
        {
            begin.CommandText = "BEGIN";
            await begin.ExecuteNonQueryAsync(Token);
        }

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() => SqliteInstaller.InstallAsync(connection, Schema.Blog(), cancellationToken: Token));

        Assert.Contains("outside an existing transaction", failure.Message, StringComparison.Ordinal);
        Assert.Empty(await ObjectsAsync(connection));
    }

    [Fact]
    public async Task ItEnforcesForeignKeysEvenOnAConnectionThatHadThemOff()
    {
        await using var connection = new SqliteConnection("Data Source=:memory:");

        await connection.OpenAsync(Token);
        await SqliteInstaller.InstallAsync(connection, Schema.Blog(), cancellationToken: Token);

        await using var insert = connection.CreateCommand();

        insert.CommandText = "INSERT INTO post (title, slug, author_id) VALUES ('t', 's', 99)";

        await Assert.ThrowsAsync<SqliteException>(() => insert.ExecuteNonQueryAsync(Token));
    }
}
