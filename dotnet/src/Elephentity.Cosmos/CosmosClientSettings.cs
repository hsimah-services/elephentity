using Azure.Core;
using Elephentity.Cosmos.Serialization;
using Microsoft.Azure.Cosmos;

namespace Elephentity.Cosmos;

/// <summary>
/// How the adaptor's own <see cref="CosmosClient" /> is configured, when it makes one.
/// </summary>
/// <remarks>
/// Defaults are the ones a small app on the free tier wants: the SDK's own retry on <c>429</c> (rate limited) is on, and
/// patient enough for a burst, because at 1000 RU/s shared a burst is a normal thing and failing the request is worse
/// than waiting for it. Nothing here does I/O; a client connects on first use.
/// </remarks>
/// <param name="MaxRetryAttemptsOnRateLimitedRequests">How many times the SDK retries a <c>429</c> before giving up.</param>
/// <param name="MaxRetryWaitTimeOnRateLimitedRequests">The most the SDK waits in total across those retries.</param>
/// <param name="ConnectionMode">Direct is faster; Gateway needs one port and is what the emulator supports.</param>
/// <param name="ApplicationName">Sent as the user agent suffix, so requests can be told apart in Azure's metrics.</param>
/// <param name="AllowAnyServerCertificate">
/// Trust any server certificate, and talk only to the endpoint given (not the account's advertised regions), over the
/// gateway. For the emulator's self-signed certificate and published port only.
/// </param>
public sealed record CosmosClientSettings(
    int MaxRetryAttemptsOnRateLimitedRequests = 9,
    TimeSpan? MaxRetryWaitTimeOnRateLimitedRequests = null,
    ConnectionMode ConnectionMode = ConnectionMode.Direct,
    string? ApplicationName = null,
    bool AllowAnyServerCertificate = false)
{
    /// <summary>The longest the SDK waits across rate-limit retries, unless set.</summary>
    public static readonly TimeSpan DefaultMaxRetryWait = TimeSpan.FromSeconds(30);

    /// <summary>The SDK options these settings mean, with the System.Text.Json serializer.</summary>
    /// <returns>Options for <see cref="CosmosClient" />.</returns>
    public CosmosClientOptions ToOptions()
    {
        var options = new CosmosClientOptions
        {
            Serializer = new CosmosSystemTextJsonSerializer(),
            MaxRetryAttemptsOnRateLimitedRequests = MaxRetryAttemptsOnRateLimitedRequests,
            MaxRetryWaitTimeOnRateLimitedRequests = MaxRetryWaitTimeOnRateLimitedRequests ?? DefaultMaxRetryWait,
            ConnectionMode = ConnectionMode,
        };

        // The SDK refuses a null name, so it is only set when there is one.
        if (!string.IsNullOrWhiteSpace(ApplicationName))
        {
            options.ApplicationName = ApplicationName;
        }

        if (AllowAnyServerCertificate)
        {
            options.HttpClientFactory = () => new HttpClient(new HttpClientHandler
            {
                ServerCertificateCustomValidationCallback = HttpClientHandler.DangerousAcceptAnyServerCertificateValidator,
            });
            options.ConnectionMode = ConnectionMode.Gateway;
            options.LimitToEndpoint = true;
        }

        return options;
    }

    /// <summary>A client for an endpoint and an Azure credential (managed identity, in a Container App).</summary>
    /// <param name="endpoint">The account endpoint.</param>
    /// <param name="credential">Who to authenticate as.</param>
    /// <returns>The client; it has not connected.</returns>
    public CosmosClient CreateClient(string endpoint, TokenCredential credential) => new(endpoint, credential, ToOptions());

    /// <summary>A client for an endpoint and an account key.</summary>
    /// <param name="endpoint">The account endpoint.</param>
    /// <param name="key">The account key; the emulator's well-known one is fine.</param>
    /// <returns>The client; it has not connected.</returns>
    public CosmosClient CreateClient(string endpoint, string key) => new(endpoint, key, ToOptions());
}
