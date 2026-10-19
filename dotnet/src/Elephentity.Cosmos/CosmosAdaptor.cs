using System.Net;
using System.Text.Json.Nodes;
using Azure.Core;
using Elephentity.Cosmos.Manifest;
using Elephentity.Cosmos.Query;
using Elephentity.Cosmos.Writes;
using Elephentity.Runtime.Capability;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Verification;
using Microsoft.Azure.Cosmos;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;
using CapabilityKind = Elephentity.Runtime.Capability.Capability;
using PatchOperationOperation = Microsoft.Azure.Cosmos.PatchOperation;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Cosmos;

/// <summary>
/// <see cref="IStorageAdaptor" /> on Azure Cosmos DB for NoSQL.
/// </summary>
/// <remarks>
/// <para>
/// <b>Layout.</b> One container, partition key <c>/pk</c>, one logical partition per project. Every document has
/// <c>id</c>, <c>pk</c> and <c>type</c> (the entity's name, or <c>__edge:Entity.edge</c> for a join document), and its
/// fields as top-level properties. Edges are a reference property on one of the two documents, or join documents;
/// <see cref="CosmosEdgePlacement" /> says which. See <c>docs/cosmos.md</c>.
/// </para>
/// <para>
/// <b>Transactions.</b> Cosmos has no multi-request transaction, but a <see cref="TransactionalBatch" /> in one logical
/// partition is atomic, and everything here is one partition. So <see cref="TransactionAsync{T}" /> collects the writes made
/// inside it (ids are made by the adaptor, so a row's id is known when it is staged) and sends them as one batch when
/// the work returns; if the work throws, nothing is sent. A write outside a transaction is its own batch. The batch holds
/// at most 100 operations, and one larger fails with <see cref="CosmosBatchTooLargeException" /> before anything is
/// sent. <b>Reads inside a transaction do not see its own staged writes.</b>
/// </para>
/// <para>
/// <b>Cost.</b> Cosmos charges request units. Queries are single-partition and parameterised, a point read is
/// the cheapest thing there is, and a read that needs another document first (an edge stored on the other side, or in
/// join documents) is two queries, not a join.
/// </para>
/// </remarks>
public sealed partial class CosmosAdaptor : IStorageAdaptor, IDisposable
{
    // Per adaptor, not static: the open transaction belongs to this container, and a write to another adaptor from
    // inside it must not be staged here.
    private readonly AsyncLocal<WriteBuffer?> active = new();

    private readonly Container container;
    private readonly CosmosStorageManifest manifest;
    private readonly CosmosQueryCompiler compiler;
    private readonly PartitionKey partition;
    private readonly ILogger log;
    private readonly CosmosClient? owned;

    /// <summary>An adaptor on a client the caller owns. Does no I/O.</summary>
    /// <param name="client">The client; the caller disposes it.</param>
    /// <param name="database">The database's name.</param>
    /// <param name="manifest">Where everything lives.</param>
    /// <param name="log">Where diagnostics go.</param>
    public CosmosAdaptor(CosmosClient client, string database, CosmosStorageManifest manifest, ILogger? log = null)
        : this(client.GetContainer(database, manifest.Container), manifest, log)
    {
    }

    /// <summary>
    /// An adaptor that makes its own client for an Azure identity, such as a Container App's managed identity. Does no I/O:
    /// the client connects on first use.
    /// </summary>
    /// <param name="endpoint">The account endpoint.</param>
    /// <param name="credential">Who to authenticate as.</param>
    /// <param name="database">The database's name.</param>
    /// <param name="manifest">Where everything lives.</param>
    /// <param name="settings">How the client behaves; the defaults suit a small app when omitted.</param>
    /// <param name="log">Where diagnostics go.</param>
    public CosmosAdaptor(
        string endpoint,
        TokenCredential credential,
        string database,
        CosmosStorageManifest manifest,
        CosmosClientSettings? settings = null,
        ILogger? log = null)
        : this((settings ?? new CosmosClientSettings()).CreateClient(endpoint, credential), database, manifest, log)
    {
        owned = ClientOf(this);
    }

    /// <summary>An adaptor on a container. Does no I/O.</summary>
    /// <param name="container">The container.</param>
    /// <param name="manifest">Where everything lives.</param>
    /// <param name="log">Where diagnostics go.</param>
    public CosmosAdaptor(Container container, CosmosStorageManifest manifest, ILogger? log = null)
    {
        RequireSerializer(container.Database.Client);
        this.container = container;
        this.manifest = manifest;
        this.log = log ?? NullLogger.Instance;
        compiler = new CosmosQueryCompiler(manifest);
        partition = new PartitionKey(manifest.PartitionKey);
    }

    /// <inheritdoc />
    public Capabilities Capabilities { get; } = new("cosmos", CapabilityKind.Transactions);

    /// <summary>Disposes the client this adaptor made, if it made one.</summary>
    public void Dispose() => owned?.Dispose();

    /// <inheritdoc />
    public async Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default)
    {
        var layout = manifest.Entity(entity);

        try
        {
            var response = await container.ReadItemAsync<JsonObject>(id.ToString(), partition, cancellationToken: cancellationToken).ConfigureAwait(false);

            // Ids are unique across entities, so an id that belongs to another entity is simply not this one's.
            return response.Resource[DocumentProperties.Type]?.GetValue<string>() == entity
                ? DocumentMapper.ToRecord(layout, response.Resource)
                : null;
        }
        catch (CosmosException exception) when (exception.StatusCode == HttpStatusCode.NotFound)
        {
            return null;
        }
    }

    /// <inheritdoc />
    public async Task<IReadOnlyList<Record>> GetManyAsync(
        string entity,
        IReadOnlyList<EntityId> ids,
        CancellationToken cancellationToken = default)
    {
        if (ids.Count == 0)
        {
            return [];
        }

        var layout = manifest.Entity(entity);
        var response = await container.ReadManyItemsAsync<JsonObject>(
            [.. ids.Distinct().Select(id => (id.ToString(), partition))],
            cancellationToken: cancellationToken).ConfigureAwait(false);

        return [.. response
            .Where(document => document[DocumentProperties.Type]?.GetValue<string>() == entity)
            .Select(document => DocumentMapper.ToRecord(layout, document))];
    }

    /// <inheritdoc />
    public async Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        if (criteria.Limit is < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(criteria), "Page size must be non-negative.");
        }

        if (criteria.Limit == 0)
        {
            return new Page<Record>([]);
        }

        var layout = manifest.Entity(criteria.Entity);
        var links = await ResolveLinksAsync(criteria, cancellationToken).ConfigureAwait(false);

        if (links.Any(link => link.MatchesNothing))
        {
            return new Page<Record>([]);
        }

        var constraints = links.Select(link => link.Constraint).ToList();
        var parented = links.FirstOrDefault(link => link.NeedsParentColumn);

        if (criteria.Limit is not { } requested)
        {
            var all = await RunAsync(compiler.Select(criteria, constraints), cancellationToken).ConfigureAwait(false);

            return new Page<Record>(Records(layout, all, parented));
        }

        var limit = Math.Min(requested, Page<Record>.MaxLimit);

        // One row per parent is more rows than documents, so a page of them cannot be asked of the query: fetch the
        // matches, expand them, and order and cut the page here.
        if (parented is not null)
        {
            return InMemoryPage(criteria, Records(layout, await RunAsync(compiler.Select(criteria, constraints), cancellationToken).ConfigureAwait(false), parented), limit);
        }

        var resume = KeysetCursor.From(criteria.After);
        var offset = resume is null ? OffsetCursor.From(criteria.After).Value : 0;

        // One row more than asked for: its presence is the answer to "is there another page".
        var documents = await RunAsync(
            compiler.Select(criteria, constraints, limit + 1, resume, offset),
            cancellationToken,
            ordering: criteria.Entity).ConfigureAwait(false);
        var records = Records(layout, documents, null);

        return Cut(criteria, records, limit);
    }

    /// <inheritdoc />
    public async Task<long> CountAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        var links = await ResolveLinksAsync(criteria, cancellationToken).ConfigureAwait(false);

        if (links.Any(link => link.MatchesNothing))
        {
            return 0;
        }

        var query = compiler.Count(criteria, [.. links.Select(link => link.Constraint)]);
        var iterator = container.GetItemQueryIterator<long>(query.ToDefinition(), requestOptions: Options());
        long total = 0;

        while (iterator.HasMoreResults)
        {
            total += (await iterator.ReadNextAsync(cancellationToken).ConfigureAwait(false)).Sum();
        }

        return total;
    }

    /// <inheritdoc />
    public async Task<WriteResult> WriteAsync(WriteBatch batch, CancellationToken cancellationToken = default)
    {
        var result = new WriteResult();

        if (active.Value is { } buffer)
        {
            await StageAsync(batch, result, buffer, cancellationToken).ConfigureAwait(false);

            return result;
        }

        var own = new WriteBuffer();
        await StageAsync(batch, result, own, cancellationToken).ConfigureAwait(false);
        await FlushAsync(own, cancellationToken).ConfigureAwait(false);

        return result;
    }

    /// <inheritdoc />
    public async Task<T> TransactionAsync<T>(Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken = default)
    {
        // Joining the outer transaction would not be a savepoint: an inner failure the caller catches would leave its
        // writes in the outer buffer to commit. MemoryAdaptor refuses too, so the two behave alike.
        if (active.Value is not null)
        {
            throw new InvalidOperationException(
                "CosmosAdaptor transactions do not nest: TransactionAsync was called from inside another TransactionAsync on the same adaptor.");
        }

        var buffer = new WriteBuffer();
        active.Value = buffer;

        try
        {
            var value = await work(cancellationToken).ConfigureAwait(false);

            // Sent only now, and only if the work finished: that is the rollback.
            active.Value = null;
            await FlushAsync(buffer, cancellationToken).ConfigureAwait(false);

            return value;
        }
        finally
        {
            active.Value = null;
        }
    }

    private QueryRequestOptions Options(int? maxItemCount = null) => new() { PartitionKey = partition, MaxItemCount = maxItemCount };

    private async Task<List<JsonObject>> RunAsync(CosmosQuery query, CancellationToken cancellationToken, string? ordering = null)
    {
        var documents = new List<JsonObject>();

        try
        {
            var iterator = container.GetItemQueryIterator<JsonObject>(query.ToDefinition(), requestOptions: Options());

            while (iterator.HasMoreResults)
            {
                documents.AddRange(await iterator.ReadNextAsync(cancellationToken).ConfigureAwait(false));
            }
        }
        catch (CosmosException exception) when (exception.StatusCode == HttpStatusCode.BadRequest
            && exception.Message.Contains("composite", StringComparison.OrdinalIgnoreCase))
        {
            throw new InvalidOperationException(
                $"Cosmos cannot order {ordering ?? "this query"} without a composite index for it. Declare the ordering in the manifest (CosmosOrdering) and re-create the container's indexing policy.",
                exception);
        }

        return documents;
    }

    private static List<Record> Records(CosmosEntityLayout layout, List<JsonObject> documents, ResolvedLink? parented)
    {
        if (parented is null)
        {
            return [.. documents.Select(document => DocumentMapper.ToRecord(layout, document))];
        }

        // One row per parent, as a join would return it, so a batch can group them.
        return [.. documents.SelectMany(document => parented.ParentsOf(document).Select(parent => DocumentMapper.ToRecord(layout, document, parent)))];
    }

    private static Page<Record> Cut(Criteria criteria, List<Record> records, int limit)
    {
        if (records.Count <= limit)
        {
            return new Page<Record>(records);
        }

        var page = records.Take(limit).ToList();

        return new Page<Record>(page, KeysetAfter(criteria, page[^1]));
    }

    /// <summary>The position after <paramref name="record" /> in the criteria's total order; never an offset.</summary>
    private static KeysetCursor KeysetAfter(Criteria criteria, Record record)
    {
        var orders = criteria.WithStableOrder().Orders;

        return new KeysetCursor([.. orders.Take(orders.Count - 1).Select(order => record.Value(order.Field))], record.Id);
    }

    private static Page<Record> InMemoryPage(Criteria criteria, List<Record> rows, int limit)
    {
        var orders = criteria.WithStableOrder().Orders;
        var ordering = new RecordOrdering(orders);
        var sorted = rows.Order(ordering).ToList();
        var skip = sorted.AsEnumerable();

        if (KeysetCursor.From(criteria.After) is { } position)
        {
            skip = skip.Where(row => ordering.IsAfter(row, position));
        }
        else
        {
            skip = skip.Skip((int)Math.Min(OffsetCursor.From(criteria.After).Value, sorted.Count));
        }

        return Cut(criteria, skip.Take(limit + 1).ToList(), limit);
    }

    // ----- edges ----------------------------------------------------------------------------------------------

    /// <summary>
    /// What one edge filter becomes, once any document that has to be read first has been.
    /// </summary>
    /// <param name="Constraint">The membership test on the queried documents.</param>
    /// <param name="NeedsParentColumn">Whether rows must say which parent they came from.</param>
    /// <param name="ParentsOf">The parents of a document, when <paramref name="NeedsParentColumn" />.</param>
    /// <param name="MatchesNothing">Whether the filter is already known to match no document.</param>
    private sealed record ResolvedLink(
        LinkConstraint Constraint,
        bool NeedsParentColumn,
        Func<JsonObject, IEnumerable<string>> ParentsOf,
        bool MatchesNothing);

    private async Task<List<ResolvedLink>> ResolveLinksAsync(Criteria criteria, CancellationToken cancellationToken)
    {
        var resolved = new List<ResolvedLink>(criteria.Links.Count);

        foreach (var link in criteria.Links)
        {
            resolved.Add(await ResolveAsync(link, cancellationToken).ConfigureAwait(false));
        }

        return resolved;
    }

    /// <summary>
    /// Mirrors the SQL adaptors: whether the key sits on the documents being queried decides if one query is enough.
    /// </summary>
    private async Task<ResolvedLink> ResolveAsync(EdgeFilter link, CancellationToken cancellationToken)
    {
        var placement = manifest.Edge(link.Entity, link.Edge);
        var from = link.From.Select(Raw).ToList();
        var needsParent = link.NeedsParentColumn();

        if (placement.UsesJoinDocuments)
        {
            // Forwards, `from` are declaring rows and the queried rows are the join's `to` side; backwards, the reverse.
            var (known, wanted) = link.Reversed
                ? (DocumentProperties.To, DocumentProperties.From)
                : (DocumentProperties.From, DocumentProperties.To);
            var pairs = await PairsAsync(placement.JoinType, known, wanted, from, cancellationToken).ConfigureAwait(false);

            return ByIds(pairs, needsParent);
        }

        if (placement.KeyIsLocal)
        {
            if (link.Reversed)
            {
                // The queried rows are the declaring ones, and the key is on them.
                return OnProperty(placement.Property, from, needsParent);
            }

            // The queried rows are targets; the declaring rows hold the key, so read those first.
            var pairs = await PairsAsync(placement.Entity, DocumentProperties.Id, placement.Property, from, cancellationToken).ConfigureAwait(false);

            return ByIds(pairs, needsParent);
        }

        // One-to-many: the key is on the target rows.
        if (!link.Reversed)
        {
            return OnProperty(placement.Property, from, needsParent);
        }

        var owners = await PairsAsync(placement.Target, DocumentProperties.Id, placement.Property, from, cancellationToken).ConfigureAwait(false);

        return ByIds(owners, needsParent);
    }

    private static ResolvedLink OnProperty(string property, List<string> from, bool needsParent) =>
        new(
            new LinkConstraint(property, from),
            needsParent,
            document => document[property]?.GetValue<string>() is { } parent ? [parent] : [],
            from.Count == 0);

    /// <param name="pairs">Queried-row id to the parents that reach it.</param>
    /// <param name="needsParent">Whether rows must say which parent they came from.</param>
    private static ResolvedLink ByIds(Dictionary<string, List<string>> pairs, bool needsParent) =>
        new(
            new LinkConstraint(DocumentProperties.Id, [.. pairs.Keys]),
            needsParent,
            document => pairs.TryGetValue(document[DocumentProperties.Id]!.GetValue<string>(), out var parents) ? parents : [],
            pairs.Count == 0);

    /// <summary>
    /// Reads documents of <paramref name="type" /> whose <paramref name="known" /> property is one of <paramref name="values" />,
    /// and returns what their <paramref name="wanted" /> property says, keyed by it, with the <paramref name="known" /> values
    /// that reach each.
    /// </summary>
    private async Task<Dictionary<string, List<string>>> PairsAsync(
        string type,
        string known,
        string wanted,
        List<string> values,
        CancellationToken cancellationToken)
    {
        var pairs = new Dictionary<string, List<string>>(StringComparer.Ordinal);

        if (values.Count == 0)
        {
            return pairs;
        }

        var text = $"SELECT {Path(known)} AS k, {Path(wanted)} AS w FROM c WHERE c.type = @type AND ARRAY_CONTAINS(@values, {Path(known)})";
        var definition = new QueryDefinition(text).WithParameter("@type", type).WithParameter("@values", values.ToArray());
        var iterator = container.GetItemQueryIterator<JsonObject>(definition, requestOptions: Options());

        while (iterator.HasMoreResults)
        {
            foreach (var row in await iterator.ReadNextAsync(cancellationToken).ConfigureAwait(false))
            {
                if (row["k"]?.GetValue<string>() is not { } key || row["w"]?.GetValue<string>() is not { } target)
                {
                    continue;
                }

                if (!pairs.TryGetValue(target, out var parents))
                {
                    pairs[target] = parents = [];
                }

                parents.Add(key);
            }
        }

        return pairs;

        static string Path(string property) => property == DocumentProperties.Id ? "c.id" : $"c[\"{property}\"]";
    }

    private static string Raw(IIdentifier identifier) =>
        identifier is EntityId id ? id.ToString() : throw new InvalidOperationException("An unsaved row has no id to query by.");

    // ----- writes ---------------------------------------------------------------------------------------------

    private async Task StageAsync(WriteBatch batch, WriteResult result, WriteBuffer buffer, CancellationToken cancellationToken)
    {
        foreach (var operation in batch.Operations)
        {
            switch (operation)
            {
                case Insert insert:
                    var layout = manifest.Entity(insert.Entity);
                    var id = CosmosIds.New();
                    buffer.Add(new CreateOperation(insert.Entity, id.ToString(), DocumentMapper.NewDocument(manifest, layout, id.ToString(), insert.Values)));
                    result.Assign(insert.PendingId, id);
                    break;
                case Update update:
                    StageUpdate(update, buffer);
                    break;
                case Delete delete:
                    _ = manifest.Entity(delete.Entity);
                    buffer.Add(new DeleteOperation(delete.Entity, delete.Id.ToString()));
                    break;
                case Link link:
                    StageLink(link, buffer);
                    break;
                case Unlink unlink:
                    await StageUnlinkAsync(unlink, buffer, cancellationToken).ConfigureAwait(false);
                    break;
                default:
                    throw new InvalidOperationException($"Unsupported write operation {operation.GetType().Name}.");
            }
        }
    }

    private void StageUpdate(Update update, WriteBuffer buffer)
    {
        if (update.Values.Count == 0)
        {
            return;
        }

        var layout = manifest.Entity(update.Entity);
        var id = update.Id.ToString();
        var sets = new Dictionary<string, JsonNode?>();

        foreach (var (field, value) in update.Values)
        {
            sets[layout.Property(field)] = DocumentMapper.ToNode(value, update.Entity, field);

            // The synthetic property that carries uniqueness moves with the field.
            for (var slot = 0; slot < layout.UniqueKeys.Count; slot++)
            {
                if (layout.UniqueKeys[slot].Field == field)
                {
                    sets[DocumentProperties.UniqueSlot(slot)] = UniqueKeys.Value(update.Entity, layout.UniqueKeys[slot], value, id);
                }
            }
        }

        buffer.Add(new Writes.PatchOperation(update.Entity, id, sets, null, Optional: false, "update"));
    }

    private void StageLink(Link link, WriteBuffer buffer)
    {
        var placement = manifest.Edge(link.Entity, link.Edge);
        var from = Raw(link.From);
        var to = Raw(link.To);

        if (placement.UsesJoinDocuments)
        {
            buffer.Add(new UpsertOperation(placement.JoinType, placement.JoinId(from, to), DocumentMapper.JoinDocument(manifest, placement, from, to)));

            return;
        }

        // The reference lives on one side; whichever it is, that document is pointed at the other.
        var (owner, value, entity) = placement.KeyIsLocal ? (from, to, placement.Entity) : (to, from, placement.Target);

        buffer.Add(new Writes.PatchOperation(
            entity,
            owner,
            new Dictionary<string, JsonNode?> { [placement.Property] = JsonValue.Create(value) },
            null,
            Optional: false,
            "link"));
    }

    private async Task StageUnlinkAsync(Unlink unlink, WriteBuffer buffer, CancellationToken cancellationToken)
    {
        var placement = manifest.Edge(unlink.Entity, unlink.Edge);
        var from = Raw(unlink.From);
        var to = unlink.To is null ? null : Raw(unlink.To);

        if (placement.UsesJoinDocuments)
        {
            if (to is not null)
            {
                buffer.Add(new DeleteOperation(placement.JoinType, placement.JoinId(from, to)));

                return;
            }

            // Every link from this row: the ids are not known without asking.
            var linked = await PairsAsync(placement.JoinType, DocumentProperties.From, DocumentProperties.To, [from], cancellationToken).ConfigureAwait(false);

            foreach (var target in linked.Keys)
            {
                buffer.Add(new DeleteOperation(placement.JoinType, placement.JoinId(from, target)));
            }

            return;
        }

        var clear = new Dictionary<string, JsonNode?> { [placement.Property] = null };

        if (placement.KeyIsLocal)
        {
            // The declaring row holds the reference. Naming a target only clears it if it still points there.
            buffer.Add(new Writes.PatchOperation(
                placement.Entity,
                from,
                clear,
                to is null ? null : (placement.Property, to),
                Optional: true,
                "unlink"));

            return;
        }

        if (to is not null)
        {
            buffer.Add(new Writes.PatchOperation(placement.Target, to, clear, (placement.Property, from), Optional: true, "unlink"));

            return;
        }

        // Every target row pointing at this owner.
        var owned = await PairsAsync(placement.Target, placement.Property, DocumentProperties.Id, [from], cancellationToken).ConfigureAwait(false);

        foreach (var target in owned.Keys)
        {
            buffer.Add(new Writes.PatchOperation(placement.Target, target, new Dictionary<string, JsonNode?>(clear), null, Optional: true, "unlink"));
        }
    }

    // ----- the batch ------------------------------------------------------------------------------------------

    private async Task FlushAsync(WriteBuffer buffer, CancellationToken cancellationToken)
    {
        var operations = buffer.Operations.ToList();

        // A patch holds at most ten operations, so a long update is several entries.
        if (Entries(operations).Count > CosmosBatchTooLargeException.Limit)
        {
            throw new CosmosBatchTooLargeException(Entries(operations).Count);
        }

        var lastFailure = string.Empty;

        // Each failure drops one operation, so there can be at most one more attempt than there are operations.
        var attempts = operations.Count + 1;

        for (var attempt = 0; attempt < attempts; attempt++)
        {
            var entries = Entries(operations);

            if (entries.Count == 0)
            {
                return;
            }

            var batch = container.CreateTransactionalBatch(partition);

            foreach (var entry in entries)
            {
                Add(batch, entry);
            }

            using var response = await batch.ExecuteAsync(cancellationToken).ConfigureAwait(false);

            if (response.IsSuccessStatusCode)
            {
                return;
            }

            var failed = Culprit(response, entries);
            lastFailure = $"{(int)response.StatusCode} {response.StatusCode} on {failed?.Operation} ({response.ErrorMessage})";

            switch (failed?.Operation)
            {
                case DeleteOperation delete:
                    // Deleting what is not there is not a failure.
                    DroppedDelete(log, delete.Entity, delete.Id);
                    operations.Remove(delete);

                    continue;
                case Writes.PatchOperation { Optional: true } patch:
                    DroppedUnlink(log, patch.Entity, patch.Id);
                    operations.Remove(patch);

                    continue;
            }

            throw await FailureAsync(response, failed, cancellationToken).ConfigureAwait(false);
        }

        throw new InvalidOperationException($"The Cosmos batch kept failing on operations that should have been droppable. Last: {lastFailure}");
    }

    private static List<(StagedOperation Operation, int Index, IReadOnlyList<Microsoft.Azure.Cosmos.PatchOperation>? Patches)> Entries(List<StagedOperation> operations)
    {
        var entries = new List<(StagedOperation, int, IReadOnlyList<Microsoft.Azure.Cosmos.PatchOperation>?)>();

        foreach (var operation in operations)
        {
            if (operation is Writes.PatchOperation patch)
            {
                var sets = patch.Sets.Select(set => (Microsoft.Azure.Cosmos.PatchOperation)Microsoft.Azure.Cosmos.PatchOperation.Set($"/{set.Key}", set.Value)).ToList();

                for (var start = 0; start < sets.Count; start += Writes.WriteLimits.PatchSize)
                {
                    entries.Add((operation, entries.Count, sets.Skip(start).Take(Writes.WriteLimits.PatchSize).ToList()));
                }
            }
            else
            {
                entries.Add((operation, entries.Count, null));
            }
        }

        return entries;
    }

    private static void Add(
        TransactionalBatch batch,
        (StagedOperation Operation, int Index, IReadOnlyList<Microsoft.Azure.Cosmos.PatchOperation>? Patches) entry)
    {
        switch (entry.Operation)
        {
            case CreateOperation create:
                batch.CreateItem(create.Document);
                break;
            case UpsertOperation upsert:
                batch.UpsertItem(upsert.Document);
                break;
            case DeleteOperation delete:
                batch.DeleteItem(delete.Id);
                break;
            case Writes.PatchOperation patch:
                if (!DocumentMapper.IsSafeId(patch.Id) || (patch.Guard is { } guard && !DocumentMapper.IsSafeId(guard.Value)))
                {
                    throw new InvalidOperationException($"{patch.Entity} id \"{patch.Id}\" cannot be used in a patch.");
                }

                batch.PatchItem(
                    patch.Id,
                    entry.Patches!,
                    patch.Guard is { } filter
                        ? new TransactionalBatchPatchItemRequestOptions { FilterPredicate = $"FROM c WHERE c[\"{filter.Property}\"] = '{filter.Value}'" }
                        : null);
                break;
            default:
                throw new InvalidOperationException($"Unsupported staged operation {entry.Operation.GetType().Name}.");
        }
    }

    /// <summary>The entry that made the batch fail: the one with a status of its own, not "failed dependency".</summary>
    private static (StagedOperation Operation, int Index, IReadOnlyList<Microsoft.Azure.Cosmos.PatchOperation>? Patches)? Culprit(
        TransactionalBatchResponse response,
        List<(StagedOperation Operation, int Index, IReadOnlyList<Microsoft.Azure.Cosmos.PatchOperation>? Patches)> entries)
    {
        for (var index = 0; index < response.Count && index < entries.Count; index++)
        {
            var status = response[index].StatusCode;

            if (!response[index].IsSuccessStatusCode && status != HttpStatusCode.FailedDependency)
            {
                return entries[index];
            }
        }

        return null;
    }

    private async Task<Exception> FailureAsync(
        TransactionalBatchResponse response,
        (StagedOperation Operation, int Index, IReadOnlyList<Microsoft.Azure.Cosmos.PatchOperation>? Patches)? failed,
        CancellationToken cancellationToken)
    {
        if (failed is null)
        {
            return new InvalidOperationException($"Cosmos refused the batch: {(int)response.StatusCode} {response.StatusCode}: {response.ErrorMessage}");
        }

        var operation = failed.Value.Operation;
        var status = response[failed.Value.Index].StatusCode;

        switch (operation)
        {
            case CreateOperation create when status == HttpStatusCode.Conflict:
                return await ConflictAsync(create, cancellationToken).ConfigureAwait(false);
            case Writes.PatchOperation patch when status == HttpStatusCode.NotFound:
                return new InvalidOperationException($"Cannot {patch.Purpose} {patch.Entity}#{patch.Id}: no such row.");
            case Writes.PatchOperation patch when status == HttpStatusCode.PreconditionFailed:
                return new InvalidOperationException($"Cannot {patch.Purpose} {patch.Entity}#{patch.Id}: it no longer holds the value this expected.");
            default:
                return new InvalidOperationException(
                    $"Cosmos refused the batch at {operation.GetType().Name.Replace("Operation", string.Empty, StringComparison.Ordinal).ToLowerInvariant()} of {operation.Entity}#{operation.Id}: {(int)status} {status}. {response.ErrorMessage}");
        }
    }

    /// <summary>
    /// A create hit a unique key (or an id that already exists). Work out which fields are taken, so the failure reads
    /// as the same violation <c>UniquenessCheck</c> reports when it sees the value first.
    /// </summary>
    private async Task<Exception> ConflictAsync(CreateOperation create, CancellationToken cancellationToken)
    {
        var layout = manifest.Entity(create.Entity);
        var violations = new List<FieldViolation>();

        for (var slot = 0; slot < layout.UniqueKeys.Count; slot++)
        {
            var property = DocumentProperties.UniqueSlot(slot);
            var definition = new QueryDefinition($"SELECT VALUE COUNT(1) FROM c WHERE c[\"{property}\"] = @value")
                .WithParameter("@value", create.Document[property]!.GetValue<string>());
            var iterator = container.GetItemQueryIterator<long>(definition, requestOptions: Options());
            long taken = 0;

            while (iterator.HasMoreResults)
            {
                taken += (await iterator.ReadNextAsync(cancellationToken).ConfigureAwait(false)).Sum();
            }

            if (taken > 0)
            {
                violations.Add(new FieldViolation(
                    create.Entity,
                    layout.UniqueKeys[slot].Field,
                    new Violation("field.unique", "Another row already holds this value, and the field is unique.")));
            }
        }

        return violations.Count > 0
            ? new CommitRejectedException(violations)
            : new InvalidOperationException($"A {create.Entity} with id {create.Id} already exists.");
    }

    /// <summary>
    /// Documents are System.Text.Json nodes, which the SDK's default (Newtonsoft) serializer would reflect over and get
    /// wrong, silently. A client that was not given ours is refused up front.
    /// </summary>
    private static void RequireSerializer(CosmosClient client)
    {
        if (client.ClientOptions?.Serializer is not Serialization.CosmosSystemTextJsonSerializer)
        {
            throw new ArgumentException(
                "The CosmosClient was not configured with CosmosSystemTextJsonSerializer, which the adaptor's documents need. "
                + "Make the client with CosmosClientSettings.CreateClient(...), or set CosmosClientOptions.Serializer = new CosmosSystemTextJsonSerializer().",
                nameof(client));
        }
    }

    private static CosmosClient ClientOf(CosmosAdaptor adaptor) => adaptor.container.Database.Client;

    [LoggerMessage(EventId = 1, Level = LogLevel.Debug, Message = "A delete of {Entity}#{Id} found nothing; dropping it from the batch.")]
    private static partial void DroppedDelete(ILogger log, string entity, string id);

    [LoggerMessage(EventId = 2, Level = LogLevel.Debug, Message = "An unlink of {Entity}#{Id} found nothing to clear; dropping it from the batch.")]
    private static partial void DroppedUnlink(ILogger log, string entity, string id);
}
