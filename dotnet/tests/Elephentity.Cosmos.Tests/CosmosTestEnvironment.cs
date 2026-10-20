using Elephentity.Cosmos.Manifest;
using Microsoft.Azure.Cosmos;

[assembly: AssemblyFixture(typeof(Elephentity.Cosmos.Tests.CosmosRun))]

namespace Elephentity.Cosmos.Tests;

/// <summary>
/// Where the Cosmos tests that need a server run: the emulator, or a real account.
/// </summary>
/// <remarks>
/// Set <c>ELEPH_COSMOS_ENDPOINT</c> (and <c>ELEPH_COSMOS_KEY</c>; the emulator's well-known key is the default). With no
/// endpoint those tests are skipped, which keeps a plain <c>dotnet test</c> green on a machine with no emulator. Each run
/// uses its own database and deletes it on exit; each test uses its own logical partition, so tests share containers but
/// not documents.
/// </remarks>
internal sealed class CosmosTestEnvironment
{
    /// <summary>The emulator's well-known key; it protects nothing.</summary>
    public const string EmulatorKey = "C2y6yDjf5/R+ob0N8A7Cgv30VRDJIWEHLM+4QDU5DE2nQ9nDuVTqobD4b8mGGyPMbIZnqyMsEcaGQy67XIw/Jw==";

    private static readonly Lazy<CosmosTestEnvironment?> Shared = new(Create);

    private readonly HashSet<string> installed = [];
    private readonly Lock gate = new();

    private CosmosTestEnvironment(CosmosClient client, string database)
    {
        Client = client;
        Database = database;
    }

    public CosmosClient Client { get; }

    /// <summary>
    /// Deletes this run's database and disposes the client, whose background threads would otherwise keep the test
    /// process from exiting. Does nothing when no environment was ever used.
    /// </summary>
    public static async ValueTask ShutdownAsync()
    {
        if (!Shared.IsValueCreated || Shared.Value is not { } environment)
        {
            return;
        }

        try
        {
            await environment.Client.GetDatabase(environment.Database).DeleteAsync().ConfigureAwait(false);
        }
        catch (CosmosException)
        {
            // The run is over; a leftover test database is not worth failing it for.
        }

        environment.Client.Dispose();
    }

    public string Database { get; }

    /// <summary>The environment, or a skipped test when none is configured.</summary>
    public static CosmosTestEnvironment Require()
    {
        if (Shared.Value is { } environment)
        {
            return environment;
        }

        Assert.Skip("Set ELEPH_COSMOS_ENDPOINT (and ELEPH_COSMOS_KEY) to run against the Cosmos emulator or an account.");

        throw new InvalidOperationException("unreachable");
    }

    /// <summary>Makes sure the manifest's container exists in this run's database.</summary>
    public void Ensure(CosmosStorageManifest manifest)
    {
        lock (gate)
        {
            if (installed.Add(manifest.Container))
            {
                Install(manifest);
            }
        }
    }

    // The emulator is slow to create containers when it is busy, and answers 408 or 503 instead of waiting; a real account does not.
    // A test-environment allowance, never the adaptor's.
    private void Install(CosmosStorageManifest manifest)
    {
        for (var attempt = 1; ; attempt++)
        {
            try
            {
                CosmosInstaller.InstallAsync(Client, Database, manifest, cancellationToken: TestContext.Current.CancellationToken).GetAwaiter().GetResult();

                return;
            }
            catch (CosmosException failure) when (attempt < 5 && failure.StatusCode is System.Net.HttpStatusCode.RequestTimeout or System.Net.HttpStatusCode.ServiceUnavailable)
            {
                Thread.Sleep(TimeSpan.FromSeconds(5 * attempt));
            }
        }
    }

    /// <summary>An adaptor for the manifest, in a partition of its own.</summary>
    public CosmosAdaptor Adaptor(CosmosStorageManifest manifest)
    {
        Ensure(manifest);

        return new CosmosAdaptor(Client, Database, manifest);
    }

    private static CosmosTestEnvironment? Create()
    {
        var endpoint = Environment.GetEnvironmentVariable("ELEPH_COSMOS_ENDPOINT");

        if (string.IsNullOrWhiteSpace(endpoint))
        {
            return null;
        }

        var key = Environment.GetEnvironmentVariable("ELEPH_COSMOS_KEY") is { Length: > 0 } configured ? configured : EmulatorKey;
        // The emulator uses a self-signed certificate and a key everyone knows; trust the one only where the other is used.
        var emulator = key == EmulatorKey;
        var client = new CosmosClientSettings(AllowAnyServerCertificate: emulator, ApplicationName: "elephentity-tests").CreateClient(endpoint, key);
        var database = $"eleph-tests-{Guid.NewGuid():N}";

        return new CosmosTestEnvironment(client, database);
    }
}

/// <summary>Runs once, after the last test in the assembly.</summary>
public sealed class CosmosRun : IAsyncLifetime
{
    /// <inheritdoc />
    public ValueTask InitializeAsync() => ValueTask.CompletedTask;

    /// <inheritdoc />
    public ValueTask DisposeAsync() => CosmosTestEnvironment.ShutdownAsync();
}
