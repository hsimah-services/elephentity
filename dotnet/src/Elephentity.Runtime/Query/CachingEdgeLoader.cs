using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Storage;
using Microsoft.Extensions.Logging;

namespace Elephentity.Runtime.Query;

/// <summary>
/// Resolves edges, and remembers what it has already resolved.
/// </summary>
/// <remarks>
/// <para>Two distinct savings, worth separating because they are often confused.</para>
/// <para><b>Laziness</b> is free: an edge accessor returns a query, so traversing a graph costs nothing until
/// something is asked of it.</para>
/// <para><b>Batching</b> is not free, and cannot be retrofitted onto a call that has already happened. Once fifty
/// posts have each been asked for their comments one at a time, fifty queries have run. Genuine batching needs a
/// caller that knows all fifty ids up front, as a GraphQL resolver does, which is why
/// <see cref="PreloadAsync" /> exists and is called there rather than guessed at here.</para>
/// <para>Between the two sits the identity map: asking the same post for its comments twice costs one query,
/// not two. One loader serves one request, so the map is not shared between threads.</para>
/// </remarks>
public sealed class CachingEdgeLoader : IEdgeLoader
{
    private readonly Dictionary<string, object?> toOne = [];
    private readonly IStorageAdaptor storage;
    private readonly IHydratorRegistry hydrators;
    private readonly IReadOnlyDictionary<string, string> targets;
    private readonly ReadGate gate;
    private readonly ILogger? log;

    /// <summary>A loader over the project's edges.</summary>
    /// <param name="storage">Where rows are read.</param>
    /// <param name="hydrators">Finds the hydrator for an entity.</param>
    /// <param name="targets">"Entity.edge" to the target entity's name.</param>
    /// <param name="gate">Enforces read policies.</param>
    /// <param name="log">Where a restarted page is logged.</param>
    public CachingEdgeLoader(
        IStorageAdaptor storage,
        IHydratorRegistry hydrators,
        IReadOnlyDictionary<string, string> targets,
        ReadGate gate,
        ILogger? log = null)
    {
        this.storage = storage;
        this.hydrators = hydrators;
        this.targets = targets;
        this.gate = gate;
        this.log = log;
    }

    /// <inheritdoc />
    public IEntityQuery<object> ToMany(string entity, EntityId id, string edge) =>
        Query(Target(entity, edge), EdgeFilter.Along(entity, edge, id));

    /// <inheritdoc />
    public ValueTask<object?> ToOneAsync(string entity, EntityId id, string edge, CancellationToken cancellationToken = default) =>
        OneAsync($"{entity}#{id}.{edge}", Target(entity, edge), EdgeFilter.Along(entity, edge, id), cancellationToken);

    /// <inheritdoc />
    public IEntityQuery<object> InverseToMany(string entity, string edge, EntityId id) =>
        // Read backwards, the rows are of the entity that declares the edge, so it is both the thing being
        // filtered and the thing being hydrated.
        Query(entity, EdgeFilter.Back(entity, edge, id));

    /// <inheritdoc />
    public ValueTask<object?> InverseToOneAsync(string entity, string edge, EntityId id, CancellationToken cancellationToken = default) =>
        OneAsync($"{entity}#{id}.{edge}^", entity, EdgeFilter.Back(entity, edge, id), cancellationToken);

    /// <summary>
    /// Resolve one edge for many parents in a single query.
    /// </summary>
    /// <remarks>
    /// The batching entry point. A caller that already holds every parent id (a GraphQL connection resolver, a
    /// report) calls this first.
    /// </remarks>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="ids">Every parent.</param>
    /// <param name="edge">The edge.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The targets each parent is linked to, keyed by the parent's id.</returns>
    public async Task<IReadOnlyDictionary<string, IReadOnlyList<object>>> PreloadAsync(
        string entity,
        IReadOnlyList<EntityId> ids,
        string edge,
        CancellationToken cancellationToken = default)
    {
        if (ids.Count == 0)
        {
            return new Dictionary<string, IReadOnlyList<object>>();
        }

        var target = Target(entity, edge);
        var hydrator = hydrators.HydratorFor(target);

        // One filter naming every parent, so this is one query rather than one each.
        var criteria = Criteria.For(target).LinkedTo(EdgeFilter.Along(entity, edge, [.. ids]));
        var grouped = ids.ToDictionary(id => id.ToString(), _ => new List<object>());

        var page = await storage.QueryAsync(criteria, cancellationToken).ConfigureAwait(false);

        foreach (var record in page.Items)
        {
            if (record.Value(EdgeFilter.ParentColumn) is { } parent)
            {
                grouped[Convert.ToString(parent, System.Globalization.CultureInfo.InvariantCulture)!].Add(hydrator.Hydrate(record, this));
            }
        }

        var result = new Dictionary<string, IReadOnlyList<object>>();

        foreach (var (parent, objects) in grouped)
        {
            result[parent] = await gate.RetainAsync(target, objects, cancellationToken).ConfigureAwait(false);
        }

        return result;
    }

    private LazyEntityQuery<object> Query(string target, EdgeFilter link) =>
        new(storage, hydrators.HydratorFor(target), this, Criteria.For(target).LinkedTo(link), gate, log);

    private async ValueTask<object?> OneAsync(string key, string target, EdgeFilter link, CancellationToken cancellationToken)
    {
        if (toOne.TryGetValue(key, out var cached))
        {
            return cached;
        }

        var found = await Query(target, link).FirstAsync(cancellationToken).ConfigureAwait(false);
        toOne[key] = found;

        return found;
    }

    private string Target(string entity, string edge) =>
        targets.TryGetValue($"{entity}.{edge}", out var target)
            ? target
            : throw new InvalidOperationException($"Edge {entity}.{edge} is not mapped.");
}
