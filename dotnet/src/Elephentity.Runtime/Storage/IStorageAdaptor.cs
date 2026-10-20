using Elephentity.Runtime.Capability;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage.Write;

namespace Elephentity.Runtime.Storage;

/// <summary>
/// The port every storage backend implements.
/// </summary>
/// <remarks>
/// <para>
/// Deliberately narrow, and deliberately defined before a second implementation exists. A
/// backend's own concepts (integer post ids, postmeta as untyped key-value, document partitions)
/// leak quietly, and by the time they are load-bearing a second adaptor is no longer possible.
/// </para>
/// <para>
/// Everything here speaks in primitives and entity names. Domain types, processors and
/// verification live above; SQL, documents and hooks live below.
/// </para>
/// <para>
/// Every operation that can reach storage is asynchronous and takes a
/// <see cref="CancellationToken" />: the Cosmos SDK is, and a synchronous port would block a thread
/// per request. That is the one deliberate divergence from the PHP port.
/// </para>
/// </remarks>
public interface IStorageAdaptor
{
    /// <summary>
    /// What this backend can do. Callers requiring more should say so explicitly rather than
    /// discovering the gap at run time.
    /// </summary>
    Capabilities Capabilities { get; }

    /// <summary>One row, or <see langword="null" /> when there is none.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="id">The row's id.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The row, if it exists.</returns>
    Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default);

    /// <summary>
    /// Fetch many rows in one round trip.
    /// </summary>
    /// <remarks>
    /// The batching primitive the edge loader is built on: one query for the comments of fifty posts
    /// rather than fifty queries.
    /// </remarks>
    /// <param name="entity">The entity.</param>
    /// <param name="ids">The ids to fetch.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The rows found, in no guaranteed order; missing ids are simply absent.</returns>
    Task<IReadOnlyList<Record>> GetManyAsync(
        string entity,
        IReadOnlyList<EntityId> ids,
        CancellationToken cancellationToken = default);

    /// <summary>
    /// Return rows in criteria order.
    /// </summary>
    /// <remarks>
    /// <para>
    /// Paged criteria end in the unique id order term; batched relationship rows with the same id
    /// must also order by their parent id. Zero pages terminate, negative limits fail, and bounded
    /// pages cap at <see cref="Page{T}.MaxLimit" />.
    /// </para>
    /// <para>
    /// <b>Resuming by keyset.</b> Given a <see cref="KeysetCursor" /> as <see cref="Criteria.After" />, return only the
    /// rows strictly after the position <c>(SortValues…, Id)</c> in the criteria's total order: its sort terms, then
    /// the id. The row at the position itself is never returned. Reading a gated entity relies on this to cost what
    /// it scans rather than everything before it, and an adaptor that cannot honour it fails the walk with an
    /// <see cref="InvalidOperationException" /> naming the entity. An adaptor's own cursors (an offset, a continuation
    /// token) are still returned in <see cref="Page{T}.Next" /> and accepted back; only a <see cref="KeysetCursor" />
    /// must be understood as a position.
    /// </para>
    /// </remarks>
    /// <param name="criteria">What to fetch.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The page of rows.</returns>
    Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default);

    /// <summary>
    /// Count matches without materialising them.
    /// </summary>
    /// <remarks>
    /// Separate from <see cref="QueryAsync" /> because a count must not pay for hydration, which is
    /// the point of the lazy edge query offering a count at all.
    /// </remarks>
    /// <param name="criteria">What to count.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>How many rows match.</returns>
    Task<long> CountAsync(Criteria criteria, CancellationToken cancellationToken = default);

    /// <summary>
    /// Apply an ordered batch of writes.
    /// </summary>
    /// <remarks>
    /// Whether this is atomic depends on <see cref="Capability.Capability.Transactions" />; callers
    /// that need all-or-nothing must require it.
    /// </remarks>
    /// <param name="batch">The writes, already in dependency order.</param>
    /// <param name="cancellationToken">Cancels the write.</param>
    /// <returns>What the batch produced, chiefly the ids assigned to inserted rows.</returns>
    Task<WriteResult> WriteAsync(WriteBatch batch, CancellationToken cancellationToken = default);

    /// <summary>
    /// Run <paramref name="work" /> inside a transaction, committing on return and rolling back on
    /// throw.
    /// </summary>
    /// <typeparam name="T">What the work returns.</typeparam>
    /// <param name="work">The work to run.</param>
    /// <param name="cancellationToken">Cancels the transaction.</param>
    /// <returns>What <paramref name="work" /> returned.</returns>
    Task<T> TransactionAsync<T>(Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken = default);
}
