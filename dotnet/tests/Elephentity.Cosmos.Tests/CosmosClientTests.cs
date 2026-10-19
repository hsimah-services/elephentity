using System.Text.Json.Nodes;
using Elephentity.Cosmos.Serialization;
using Microsoft.Azure.Cosmos;

namespace Elephentity.Cosmos.Tests;

/// <summary>What needs no server: the serializer, the settings, and the checks made before anything is sent.</summary>
public sealed class CosmosClientTests
{
    private const string Endpoint = "https://localhost:1/";

    private static CosmosClient Client(CosmosClientSettings? settings = null) =>
        (settings ?? new CosmosClientSettings()).CreateClient(Endpoint, CosmosTestEnvironment.EmulatorKey);

    [Fact]
    public void DocumentsAndParametersRoundTripWithoutReflection()
    {
        var serializer = new CosmosSystemTextJsonSerializer();
        var document = new JsonObject { ["id"] = "a", ["n"] = 3, ["ok"] = true, ["tags"] = new JsonArray("x", "y"), ["gone"] = null };

        var back = serializer.FromStream<JsonObject>(serializer.ToStream(document));

        Assert.Equal(document.ToJsonString(), back.ToJsonString());
        string[] names = ["a", "b"];
        Assert.Equal("[\"a\",\"b\"]", Read(serializer.ToStream(names)));
        Assert.Equal("7", Read(serializer.ToStream(7L)));
        Assert.Equal("null", Read(serializer.ToStream<object?>(null)));
        Assert.Equal("\"text\"", Read(serializer.ToStream(JsonValue.Create("text"))));
        Assert.Equal(12L, serializer.FromStream<long>(new MemoryStream("12"u8.ToArray())));
    }

    [Fact]
    public void ATypeNobodyRegisteredIsRefusedByName()
    {
        var serializer = new CosmosSystemTextJsonSerializer();

        var failure = Assert.Throws<NotSupportedException>(() => serializer.ToStream(new Uri("https://example.com")));

        Assert.Contains("System.Uri", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void TheDefaultSettingsRetryRateLimitingPatientlyAndUseOurSerializer()
    {
        var options = new CosmosClientSettings().ToOptions();

        Assert.IsType<CosmosSystemTextJsonSerializer>(options.Serializer);
        Assert.Equal(9, options.MaxRetryAttemptsOnRateLimitedRequests);
        Assert.Equal(TimeSpan.FromSeconds(30), options.MaxRetryWaitTimeOnRateLimitedRequests);
        Assert.Equal(ConnectionMode.Direct, options.ConnectionMode);
    }

    [Fact]
    public void RetryingCanBeTunedAndTheEmulatorSettingsTrustItsCertificate()
    {
        var tuned = new CosmosClientSettings(MaxRetryAttemptsOnRateLimitedRequests: 3, MaxRetryWaitTimeOnRateLimitedRequests: TimeSpan.FromSeconds(5)).ToOptions();
        var emulator = new CosmosClientSettings(AllowAnyServerCertificate: true).ToOptions();

        Assert.Equal(3, tuned.MaxRetryAttemptsOnRateLimitedRequests);
        Assert.Equal(TimeSpan.FromSeconds(5), tuned.MaxRetryWaitTimeOnRateLimitedRequests);
        Assert.Equal(ConnectionMode.Gateway, emulator.ConnectionMode);
        Assert.True(emulator.LimitToEndpoint);
        Assert.NotNull(emulator.HttpClientFactory);
    }

    [Fact]
    public void ConstructingAnAdaptorDoesNoIo()
    {
        using var client = Client();

        // Nothing listens at the endpoint: any request would fail. Constructing must not make one.
        _ = new CosmosAdaptor(client, "db", Layouts.Blog());
    }

    [Fact]
    public void AClientWithoutOurSerializerIsRefusedBecauseItWouldMisreadEveryDocument()
    {
        using var plain = new CosmosClient(Endpoint, CosmosTestEnvironment.EmulatorKey);

        var failure = Assert.Throws<ArgumentException>(() => new CosmosAdaptor(plain, "db", Layouts.Blog()));

        Assert.Contains("CosmosSystemTextJsonSerializer", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task AWriteOverTheBatchLimitFailsBeforeAnythingIsSent()
    {
        using var client = Client();
        var adaptor = new CosmosAdaptor(client, "db", Layouts.Blog());
        var inserts = Enumerable.Range(0, 101)
            .Select(_ => (Elephentity.Runtime.Storage.Write.IWriteOperation)new Elephentity.Runtime.Storage.Write.Insert(
                "Tag",
                new Elephentity.Runtime.Identity.PendingId("Tag"),
                new Dictionary<string, object?> { ["label"] = "x" }))
            .ToArray();

        // The endpoint is unreachable, so a request would throw something else.
        var failure = await Assert.ThrowsAsync<CosmosBatchTooLargeException>(() =>
            adaptor.WriteAsync(new Elephentity.Runtime.Storage.Write.WriteBatch(inserts), TestContext.Current.CancellationToken));

        Assert.Equal(101, failure.Operations);
        Assert.Contains("at most 100", failure.Message, StringComparison.Ordinal);
    }

    private static string Read(Stream stream)
    {
        using var reader = new StreamReader(stream);

        return reader.ReadToEnd();
    }
}
