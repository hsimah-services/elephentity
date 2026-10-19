using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Storage;
using Microsoft.Extensions.Logging;

namespace Elephentity.Runtime.Query;

/// <summary>
/// Builds a lazy query, so a hand-written finder does not have to.
/// </summary>
/// <remarks>
/// A generated <c>ItemFinder</c> delegates to an <c>ItemSearchQuery</c> the application writes, and that class
/// has to return an <c>IEntityQuery&lt;Item&gt;</c>. Constructing one directly means assembling a storage
/// adaptor, the right hydrator and an edge loader: four collaborators to answer "items whose name contains
/// this", which is enough friction to make people reach past the port. Taking the hydrator rather than an entity
/// name is what keeps the result exactly typed.
/// </remarks>
public sealed class Queries
{
    private readonly IStorageAdaptor storage;
    private readonly IEdgeLoader edges;
    private readonly ReadGate gate;
    private readonly ILogger? log;

    /// <summary>A query builder over the assembled collaborators.</summary>
    /// <param name="storage">Where rows are read.</param>
    /// <param name="edges">Resolves the entities' edges.</param>
    /// <param name="gate">Enforces read policies.</param>
    /// <param name="log">Where a restarted page is logged.</param>
    public Queries(IStorageAdaptor storage, IEdgeLoader edges, ReadGate gate, ILogger? log = null)
    {
        this.storage = storage;
        this.edges = edges;
        this.gate = gate;
        this.log = log;
    }

    /// <summary>A lazy query for <paramref name="criteria" />.</summary>
    /// <typeparam name="T">The entity type.</typeparam>
    /// <param name="hydrator">Builds the entities.</param>
    /// <param name="criteria">What to fetch.</param>
    /// <returns>The query.</returns>
    public IEntityQuery<T> Of<T>(IHydrator<T> hydrator, Criteria criteria)
        where T : class =>
        new LazyEntityQuery<T>(storage, hydrator, edges, criteria, gate, log);
}
