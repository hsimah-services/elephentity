using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Testing;
using Elephentity.Runtime.Testing.Xunit;
using Elephentity.Sqlite;
using Elephentity.Sqlite.Manifest;
using Microsoft.Data.Sqlite;

namespace Golden.Sqlite.Tests;

/// <summary>Opens databases for the generated manifests, with their schema installed.</summary>
internal static class Database
{
    public static async Task<SqliteConnection> OpenAsync(SqliteStorageManifest manifest, string prefix = "test_")
    {
        var connection = new SqliteConnection("Data Source=:memory:");

        await connection.OpenAsync();
        await SqliteInstaller.InstallAsync(connection, manifest, prefix);

        return connection;
    }

    public static SqliteAdaptor Adaptor(Func<SqliteStorageManifest> create)
    {
        var manifest = create().WithPrefix("test_");
        var connection = OpenAsync(create()).GetAwaiter().GetResult();

        return new SqliteAdaptor(connection, manifest);
    }

    public static async Task<List<string[]>> RowsAsync(SqliteConnection connection, string sql)
    {
        await using var command = connection.CreateCommand();

        command.CommandText = sql;

        var rows = new List<string[]>();
        await using var reader = await command.ExecuteReaderAsync();

        while (await reader.ReadAsync())
        {
            rows.Add([.. Enumerable.Range(0, reader.FieldCount).Select(index => reader.IsDBNull(index) ? "NULL" : Convert.ToString(reader.GetValue(index), System.Globalization.CultureInfo.InvariantCulture)!)]);
        }

        return rows;
    }

    public static async Task ExecuteAsync(SqliteConnection connection, string sql)
    {
        await using var command = connection.CreateCommand();

        command.CommandText = sql;

        await command.ExecuteNonQueryAsync();
    }
}

/// <summary>
/// The conformance suite, on SQLite, over each relation shape's generated manifest. The shared-target case links a second
/// target to an owner that already has one, which only a many-to-many allows, so only that shape turns it on.
/// </summary>
public sealed class OneToOneConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target");

    protected override IStorageAdaptor CreateAdaptor() => Database.Adaptor(Golden.OneToOne.SqliteStorageManifestFactory.Create);
}

public sealed class ManyToOneConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target");

    protected override IStorageAdaptor CreateAdaptor() => Database.Adaptor(Golden.ManyToOne.SqliteStorageManifestFactory.Create);
}

public sealed class OneToManyConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target");

    protected override IStorageAdaptor CreateAdaptor() => Database.Adaptor(Golden.OneToMany.SqliteStorageManifestFactory.Create);
}

public sealed class ManyToManyConformance : AdaptorConformanceTests
{
    protected override ConformanceNames Names { get; } = new("Owner", "target", "Target", SharedTargets: true);

    protected override IStorageAdaptor CreateAdaptor() => Database.Adaptor(Golden.ManyToMany.SqliteStorageManifestFactory.Create);
}

/// <summary>What the generated schema does when SQLite runs it, as <c>tests/runtime.php</c> checks it for PHP.</summary>
public sealed class GeneratedManifestTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    public static TheoryData<string> Fixtures { get; } = ["clog", "relations", "one-to-one", "many-to-one", "one-to-many", "many-to-many"];

    private static SqliteStorageManifest Load(string fixture) => fixture switch
    {
        "clog" => Golden.Clog.SqliteStorageManifestFactory.Create(),
        "relations" => Golden.Relations.SqliteStorageManifestFactory.Create(),
        "one-to-one" => Golden.OneToOne.SqliteStorageManifestFactory.Create(),
        "many-to-one" => Golden.ManyToOne.SqliteStorageManifestFactory.Create(),
        "one-to-many" => Golden.OneToMany.SqliteStorageManifestFactory.Create(),
        "many-to-many" => Golden.ManyToMany.SqliteStorageManifestFactory.Create(),
        _ => throw new ArgumentOutOfRangeException(nameof(fixture)),
    };

    [Theory]
    [MemberData(nameof(Fixtures))]
    public async Task EveryManifestInstallsAndTheManifestDescribesWhatWasInstalled(string fixture)
    {
        var manifest = Load(fixture);
        await using var connection = await Database.OpenAsync(manifest);
        var prefixed = manifest.WithPrefix("test_");

        Assert.NotEmpty(manifest.Tables);

        foreach (var table in prefixed.EveryTable())
        {
            var installed = await Database.RowsAsync(connection, $"PRAGMA table_info(\"{table.Name}\")");

            Assert.Equal(table.Columns.Select(column => column.Name), installed.Select(row => row[1]));
            Assert.Equal(table.Columns.Select(column => column.Type), installed.Select(row => row[2]));
        }

        Assert.Empty(await Database.RowsAsync(connection, "PRAGMA foreign_key_check"));
        Assert.Equal(
            prefixed.EveryTable().SelectMany(table => table.Indexes.Select(index => index.Name)).Order(StringComparer.Ordinal),
            (await Database.RowsAsync(connection, "SELECT name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%' ORDER BY name")).Select(row => row[0]));
    }

    [Theory]
    [MemberData(nameof(Fixtures))]
    public async Task InstallingAgainRefusesAndChangesNothing(string fixture)
    {
        var manifest = Load(fixture);
        await using var connection = await Database.OpenAsync(manifest);
        var before = await Database.RowsAsync(connection, "SELECT name, sql FROM sqlite_master ORDER BY name");

        await Assert.ThrowsAsync<SqliteException>(() => SqliteInstaller.InstallAsync(connection, manifest, "test_", Token));
        await Assert.ThrowsAsync<ArgumentException>(() => SqliteInstaller.InstallAsync(connection, manifest, "bad\"prefix", Token));

        Assert.Equal(before, await Database.RowsAsync(connection, "SELECT name, sql FROM sqlite_master ORDER BY name"));
    }

    [Fact]
    public async Task TheManifestInstallsWithoutAPrefixToo()
    {
        await using var connection = await Database.OpenAsync(Golden.Relations.SqliteStorageManifestFactory.Create(), string.Empty);

        Assert.NotEmpty(await Database.RowsAsync(connection, "SELECT name FROM sqlite_master WHERE name = 'phe_post'"));
    }

    [Fact]
    public async Task ConstraintsHostileLiteralsNullsAndForeignKeysAreEnforced()
    {
        await using var connection = await Database.OpenAsync(Golden.Constraints.SqliteStorageManifestFactory.Create(), string.Empty);

        await Database.ExecuteAsync(connection, "INSERT INTO target (name) VALUES ('quote''\"{prefix}\\')");
        await Database.ExecuteAsync(connection, "INSERT INTO owner (name, rank) VALUES ('first', 1)");

        await Assert.ThrowsAsync<SqliteException>(() => Database.ExecuteAsync(connection, "INSERT INTO owner (name) VALUES ('first')"));
        await Assert.ThrowsAsync<SqliteException>(() => Database.ExecuteAsync(connection, "INSERT INTO owner (name) VALUES ('longer')"));
        await Assert.ThrowsAsync<SqliteException>(() => Database.ExecuteAsync(connection, "INSERT INTO owner (rank) VALUES (2)"));
        await Assert.ThrowsAsync<SqliteException>(() => Database.ExecuteAsync(connection, "INSERT INTO target (name) VALUES ('unknown')"));
        await Assert.ThrowsAsync<SqliteException>(() => Database.ExecuteAsync(connection, "INSERT INTO owner (target_id) VALUES (999)"));

        // NULL is preserved, and is no value for a unique index.
        await Database.ExecuteAsync(connection, "UPDATE owner SET name = NULL WHERE id = 1");
        await Database.ExecuteAsync(connection, "INSERT INTO owner (name) VALUES (NULL)");
        await Database.ExecuteAsync(connection, "INSERT INTO owner (name) VALUES (NULL)");

        Assert.Equal("NULL", (await Database.RowsAsync(connection, "SELECT name FROM owner WHERE id = 1"))[0][0]);
    }

    [Fact]
    public async Task TheInstallerRefusesASurroundingTransactionAndRollsBackAPartialInstall()
    {
        var manifest = Golden.Constraints.SqliteStorageManifestFactory.Create();

        await using (var open = new SqliteConnection("Data Source=:memory:"))
        {
            await open.OpenAsync(Token);
            await Database.ExecuteAsync(open, "BEGIN");
            await Assert.ThrowsAsync<InvalidOperationException>(() => SqliteInstaller.InstallAsync(open, manifest, "nested_", Token));
        }

        await using var partial = new SqliteConnection("Data Source=:memory:");

        await partial.OpenAsync(Token);
        await Database.ExecuteAsync(partial, "CREATE TABLE target (existing TEXT)");
        await Assert.ThrowsAsync<SqliteException>(() => SqliteInstaller.InstallAsync(partial, manifest, cancellationToken: Token));

        Assert.Empty(await Database.RowsAsync(partial, "SELECT name FROM sqlite_master WHERE name = 'owner'"));
    }

    [Fact]
    public async Task ColumnDefaultsFillTheFieldsAnInsertLeavesOut()
    {
        var manifest = Golden.Defaults.SqliteStorageManifestFactory.Create().WithPrefix("test_");
        await using var connection = await Database.OpenAsync(Golden.Defaults.SqliteStorageManifestFactory.Create());
        using var adaptor = new SqliteAdaptor(connection, manifest);
        var pending = new PendingId("Owner");
        var id = (await adaptor.WriteAsync(new WriteBatch(new Insert("Owner", pending, new Dictionary<string, object?>())), Token)).IdFor(pending);
        var row = await adaptor.GetAsync("Owner", id, Token);

        Assert.Equal(0L, row!.Value("checked"));
        Assert.Equal(7L, row.Value("rank"));
        Assert.Equal("it's {prefix}\\", row.Value("name"));
    }

    [Theory]
    [InlineData("enums")]
    [InlineData("enums-inferred")]
    public async Task AnIntegerEnumStoresOrdinalsAndRefusesLabelsAndOutOfRangeValues(string fixture)
    {
        var create = fixture == "enums" ? Golden.Enums.SqliteStorageManifestFactory.Create : (Func<SqliteStorageManifest>)Golden.EnumsInferred.SqliteStorageManifestFactory.Create;
        await using var connection = await Database.OpenAsync(create(), string.Empty);
        using var adaptor = new SqliteAdaptor(connection, create());

        foreach (var ordinal in new long[] { 0, 1, 2 })
        {
            var pending = new PendingId("Target");
            var id = (await adaptor.WriteAsync(new WriteBatch(new Insert("Target", pending, new Dictionary<string, object?> { ["name"] = ordinal })), Token)).IdFor(pending);

            Assert.Equal(ordinal, (await adaptor.GetAsync("Target", id, Token))!.Value("name"));
            await adaptor.WriteAsync(new WriteBatch(new Update("Target", id, new Dictionary<string, object?> { ["name"] = 2L })), Token);
        }

        foreach (var refused in new[] { "'draft'", "-1", "3" })
        {
            await Assert.ThrowsAsync<SqliteException>(() => Database.ExecuteAsync(connection, $"INSERT INTO target (name) VALUES ({refused})"));
        }
    }
}
