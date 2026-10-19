using Elephentity.Cosmos;
using Elephentity.Cosmos.Manifest;
using Microsoft.Azure.Cosmos;

[assembly: AssemblyFixture(typeof(Golden.Cosmos.Tests.EmulatorRun))]

namespace Golden.Cosmos.Tests;

/// <summary>
/// The Cosmos emulator (or an account) the generated manifests are installed into. One database per run, deleted at the
/// end; one container per manifest; one logical partition per test, so each test starts from nothing.
/// </summary>
internal static class Emulator
{
    /// <summary>The emulator's well-known key; it protects nothing.</summary>
    private const string EmulatorKey = "C2y6yDjf5/R+ob0N8A7Cgv30VRDJIWEHLM+4QDU5DE2nQ9nDuVTqobD4b8mGGyPMbIZnqyMsEcaGQy67XIw/Jw==";

    private static readonly Lazy<(CosmosClient Client, string Database)?> Shared = new(Create);
    private static readonly HashSet<string> Installed = [];
    private static readonly Lock Gate = new();

    private static (CosmosClient, string)? Create()
    {
        if (Environment.GetEnvironmentVariable("ELEPH_COSMOS_ENDPOINT") is not { Length: > 0 } endpoint)
        {
            return null;
        }

        var key = Environment.GetEnvironmentVariable("ELEPH_COSMOS_KEY") is { Length: > 0 } configured ? configured : EmulatorKey;
        var client = new CosmosClientSettings(AllowAnyServerCertificate: key == EmulatorKey, ApplicationName: "elephentity-codegen-cosmos-tests")
            .CreateClient(endpoint, key);

        return (client, $"eleph-gen-{Guid.NewGuid():N}");
    }

    /// <summary>An adaptor over the manifest, in a partition of its own, or a skipped test when there is no server.</summary>
    public static CosmosAdaptor Adaptor(Func<string, CosmosStorageManifest> factory, string container)
    {
        if (Shared.Value is not { } environment)
        {
            Assert.Skip("Set ELEPH_COSMOS_ENDPOINT (and ELEPH_COSMOS_KEY) to run against the Cosmos emulator or an account.");

            throw new InvalidOperationException("unreachable");
        }

        var generated = factory(container);

        lock (Gate)
        {
            if (Installed.Add(container))
            {
                Install(environment.Client, environment.Database, generated);
            }
        }

        // The generated partition is the project's; a fresh one makes an empty adaptor over the same container.
        var isolated = new CosmosStorageManifest($"t-{Guid.NewGuid():N}", generated.Entities, generated.Orderings, generated.Container);

        return new CosmosAdaptor(environment.Client, environment.Database, isolated);
    }

    /// <summary>
    /// The emulator is slow to create containers when it is busy, and answers 408 or 503 instead of waiting. A real account
    /// does not, so this is a test-environment allowance, not something the adaptor does.
    /// </summary>
    private static void Install(CosmosClient client, string database, CosmosStorageManifest manifest)
    {
        for (var attempt = 1; ; attempt++)
        {
            try
            {
                CosmosInstaller.InstallAsync(client, database, manifest, cancellationToken: TestContext.Current.CancellationToken)
                    .GetAwaiter()
                    .GetResult();

                return;
            }
            catch (CosmosException failure) when (attempt < 5 && failure.StatusCode is System.Net.HttpStatusCode.RequestTimeout or System.Net.HttpStatusCode.ServiceUnavailable)
            {
                Thread.Sleep(TimeSpan.FromSeconds(5 * attempt));
            }
        }
    }

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
}

/// <summary>Runs once, after the last test in the assembly.</summary>
public sealed class EmulatorRun : IAsyncLifetime
{
    public ValueTask InitializeAsync() => ValueTask.CompletedTask;

    public ValueTask DisposeAsync() => Emulator.ShutdownAsync();
}
