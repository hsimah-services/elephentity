using System.Text;
using System.Text.Json.Nodes;
using Microsoft.Azure.Cosmos;

namespace Elephentity.Cosmos;

/// <summary>
/// Export a container to JSON Lines, and import one into an empty container.
/// </summary>
/// <remarks>
/// <para>
/// This is the migration path for the one thing a container cannot change in place, its unique keys: export it, create a
/// new container with the new manifest, and import into that. It is not a backup strategy. Azure Cosmos DB takes its own
/// periodic backups of every account (and continuous backup, with point-in-time restore, can be turned on); use those for
/// recovery, and this for moving data between container definitions or out of Azure.
/// </para>
/// <para>
/// One document per line, as stored, minus the system properties Cosmos maintains (<c>_rid</c>, <c>_self</c>,
/// <c>_etag</c>, <c>_attachments</c>, <c>_ts</c>). Import writes in transactional batches of one partition, so a document
/// is never half imported, but the import as a whole is not atomic: a failure stops it, leaving the earlier batches in place.
/// </para>
/// </remarks>
public static class CosmosBackup
{
    private static readonly string[] SystemProperties = ["_rid", "_self", "_etag", "_attachments", "_ts"];

    /// <summary>Writes every document in the container, one per line.</summary>
    /// <param name="container">The container to read; every partition is read.</param>
    /// <param name="destination">Where the lines go, as UTF-8.</param>
    /// <param name="cancellationToken">Cancels the export.</param>
    /// <returns>How many documents were written.</returns>
    public static async Task<long> ExportAsync(Container container, Stream destination, CancellationToken cancellationToken = default)
    {
        await using var writer = new StreamWriter(destination, new UTF8Encoding(false), leaveOpen: true) { NewLine = "\n" };
        var iterator = container.GetItemQueryIterator<JsonObject>(new QueryDefinition("SELECT * FROM c"));
        long count = 0;

        while (iterator.HasMoreResults)
        {
            foreach (var document in await iterator.ReadNextAsync(cancellationToken).ConfigureAwait(false))
            {
                foreach (var property in SystemProperties)
                {
                    document.Remove(property);
                }

                await writer.WriteLineAsync(document.ToJsonString()).ConfigureAwait(false);
                count++;
            }
        }

        await writer.FlushAsync(cancellationToken).ConfigureAwait(false);

        return count;
    }

    /// <summary>Restores documents written by <see cref="ExportAsync" /> into an empty container.</summary>
    /// <param name="container">The container to fill; it must be empty.</param>
    /// <param name="source">The lines, as UTF-8.</param>
    /// <param name="cancellationToken">Cancels the import.</param>
    /// <returns>How many documents were imported.</returns>
    /// <exception cref="ContainerNotEmptyException">The container already holds documents.</exception>
    public static async Task<long> ImportAsync(Container container, Stream source, CancellationToken cancellationToken = default)
    {
        if (!await CosmosInstaller.IsEmptyAsync(container, cancellationToken).ConfigureAwait(false))
        {
            throw new ContainerNotEmptyException(container.Id, "importing");
        }

        using var reader = new StreamReader(source, new UTF8Encoding(false), leaveOpen: true);
        var pending = new Dictionary<string, List<JsonObject>>(StringComparer.Ordinal);
        long count = 0;
        string? line;

        while ((line = await reader.ReadLineAsync(cancellationToken).ConfigureAwait(false)) is not null)
        {
            if (string.IsNullOrWhiteSpace(line))
            {
                continue;
            }

            var document = JsonNode.Parse(line)?.AsObject()
                ?? throw new InvalidOperationException($"Line {count + 1} of the export is not a JSON object.");
            var partition = document[DocumentProperties.PartitionKey]?.GetValue<string>()
                ?? throw new InvalidOperationException($"Line {count + 1} of the export has no \"pk\".");

            if (!pending.TryGetValue(partition, out var batch))
            {
                pending[partition] = batch = [];
            }

            batch.Add(document);
            count++;

            if (batch.Count == Writes.WriteLimits.BatchSize)
            {
                await WriteAsync(container, partition, batch, cancellationToken).ConfigureAwait(false);
                batch.Clear();
            }
        }

        foreach (var (partition, batch) in pending.Where(entry => entry.Value.Count > 0))
        {
            await WriteAsync(container, partition, batch, cancellationToken).ConfigureAwait(false);
        }

        return count;
    }

    private static async Task WriteAsync(Container container, string partition, List<JsonObject> documents, CancellationToken cancellationToken)
    {
        var batch = container.CreateTransactionalBatch(new PartitionKey(partition));

        foreach (var document in documents)
        {
            batch.CreateItem(document);
        }

        using var response = await batch.ExecuteAsync(cancellationToken).ConfigureAwait(false);

        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException($"Importing into partition \"{partition}\" failed: {(int)response.StatusCode} {response.StatusCode}: {response.ErrorMessage}");
        }
    }
}
