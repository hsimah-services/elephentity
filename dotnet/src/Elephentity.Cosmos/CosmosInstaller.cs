using System.Collections.ObjectModel;
using System.Net;
using System.Text.Json.Nodes;
using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Storage;
using Microsoft.Azure.Cosmos;

namespace Elephentity.Cosmos;

/// <summary>
/// Creates the database and container a manifest needs.
/// </summary>
/// <remarks>
/// <para>
/// Installing and migrating existing data are different operations, and this is only the first. If the container already
/// holds a document it fails (<see cref="ContainerNotEmptyException" />) and changes nothing, which is what an installer
/// that someone might run twice should do. An empty container that already exists and was created the way the manifest
/// says is left alone apart from its indexing policy, so installing again is harmless.
/// </para>
/// <para>
/// The database has <em>shared</em> throughput (400 RU/s by default, which fits the free tier's 1000 with room for
/// others), so the container costs nothing extra. The container is partitioned on <c>/pk</c>, with one unique key per
/// synthetic slot (<c>/_u0</c>, …) and a composite index for every ordering the manifest declares. <b>Unique keys cannot be
/// changed once a container exists</b>; the way to change them is export, a new container, import.
/// </para>
/// </remarks>
public static class CosmosInstaller
{
    /// <summary>The default shared throughput, in request units per second.</summary>
    public const int DefaultThroughput = 400;

    /// <summary>Creates what the manifest needs.</summary>
    /// <param name="client">The client; it needs rights to create databases.</param>
    /// <param name="database">The database's name.</param>
    /// <param name="manifest">The manifest.</param>
    /// <param name="throughput">The database's shared throughput; used only when the database is created.</param>
    /// <param name="cancellationToken">Cancels the install.</param>
    /// <returns>A task that completes when the container exists.</returns>
    /// <exception cref="ContainerNotEmptyException">The container exists and holds a document.</exception>
    /// <exception cref="InvalidOperationException">The container exists, is empty, and was created with other unique keys or another partition key.</exception>
    public static async Task InstallAsync(
        CosmosClient client,
        string database,
        CosmosStorageManifest manifest,
        int throughput = DefaultThroughput,
        CancellationToken cancellationToken = default)
    {
        var created = await client.CreateDatabaseIfNotExistsAsync(
            database,
            ThroughputProperties.CreateManualThroughput(throughput),
            cancellationToken: cancellationToken).ConfigureAwait(false);
        var wanted = Properties(manifest);
        var container = created.Database.GetContainer(manifest.Container);

        ContainerProperties existing;

        try
        {
            existing = (await container.ReadContainerAsync(cancellationToken: cancellationToken).ConfigureAwait(false)).Resource;
        }
        catch (CosmosException exception) when (exception.StatusCode == HttpStatusCode.NotFound)
        {
            await created.Database.CreateContainerAsync(wanted, cancellationToken: cancellationToken).ConfigureAwait(false);

            return;
        }

        if (!await IsEmptyAsync(container, cancellationToken).ConfigureAwait(false))
        {
            throw new ContainerNotEmptyException(manifest.Container, "installing");
        }

        if (existing.PartitionKeyPath != wanted.PartitionKeyPath
            || !existing.UniqueKeyPolicy.UniqueKeys.Select(key => string.Join(",", key.Paths))
                .SequenceEqual(wanted.UniqueKeyPolicy.UniqueKeys.Select(key => string.Join(",", key.Paths))))
        {
            throw new InvalidOperationException(
                $"The empty container \"{manifest.Container}\" exists with a different partition key or unique keys than the manifest, and those cannot be changed in place. Delete it and install again.");
        }

        // Everything else about an empty container can move: the indexing policy is the one thing the manifest may have changed.
        await container.ReplaceContainerAsync(wanted, cancellationToken: cancellationToken).ConfigureAwait(false);
    }

    /// <summary>The container's properties as the manifest means them.</summary>
    /// <param name="manifest">The manifest.</param>
    /// <returns>Properties to create the container with.</returns>
    public static ContainerProperties Properties(CosmosStorageManifest manifest)
    {
        var properties = new ContainerProperties(manifest.Container, "/" + DocumentProperties.PartitionKey);

        for (var slot = 0; slot < manifest.UniqueSlots; slot++)
        {
            var key = new UniqueKey();
            key.Paths.Add("/" + DocumentProperties.UniqueSlot(slot));
            properties.UniqueKeyPolicy.UniqueKeys.Add(key);
        }

        var indexing = properties.IndexingPolicy;
        indexing.IndexingMode = IndexingMode.Consistent;
        indexing.IncludedPaths.Add(new IncludedPath { Path = "/*" });
        indexing.ExcludedPaths.Add(new ExcludedPath { Path = "/\"_etag\"/?" });

        // The synthetic unique-key properties are never queried by value, so they need no index.
        for (var slot = 0; slot < manifest.UniqueSlots; slot++)
        {
            indexing.ExcludedPaths.Add(new ExcludedPath { Path = $"/{DocumentProperties.UniqueSlot(slot)}/?" });
        }

        // The indexing policy belongs to the container, not to an entity, and Cosmos refuses the same composite twice. Two
        // entities that sort by the same property (a `rank` on both) want the same index, so it is made once.
        var made = new HashSet<string>(StringComparer.Ordinal);

        foreach (var ordering in manifest.Orderings)
        {
            var layout = manifest.Entity(ordering.Entity);
            var terms = ordering.Terms.ToList();

            if (terms[^1].Field != "id")
            {
                terms.Add(Order.Ascending("id"));
            }

            var composite = terms.Select(term => new CompositePath
            {
                Path = "/" + layout.Property(term.Field),
                Order = term.Direction == Direction.Descending ? CompositePathSortOrder.Descending : CompositePathSortOrder.Ascending,
            }).ToList();

            if (made.Add(string.Join('|', composite.Select(path => $"{path.Path}:{path.Order}"))))
            {
                indexing.CompositeIndexes.Add(new Collection<CompositePath>(composite));
            }
        }

        return properties;
    }

    internal static async Task<bool> IsEmptyAsync(Container container, CancellationToken cancellationToken)
    {
        var iterator = container.GetItemQueryIterator<JsonObject>(new QueryDefinition("SELECT TOP 1 c.id FROM c"));

        while (iterator.HasMoreResults)
        {
            if ((await iterator.ReadNextAsync(cancellationToken).ConfigureAwait(false)).Count > 0)
            {
                return false;
            }
        }

        return true;
    }
}
