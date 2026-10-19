using Elephentity.Runtime.Storage;

namespace Elephentity.Runtime.Query;

/// <summary>
/// A lazy, batchable handle on a set of entities.
/// </summary>
/// <remarks>
/// Returned by every to-many edge accessor and every generated finder, so traversal and custom queries behave
/// identically. Not a list, for three reasons: an unbounded load becomes a deliberate <see cref="AllAsync" />
/// rather than the default; GraphQL connections map onto <see cref="PageAsync" /> directly instead of a resolver
/// slicing an already-hydrated list; and the loader can batch across a result set, which is the N+1 defence and
/// is very hard to add once code everywhere assumes a list.
/// </remarks>
/// <typeparam name="T">The entity type.</typeparam>
public interface IEntityQuery<T>
    where T : class
{
    /// <summary>How many entities match.</summary>
    /// <param name="cancellationToken">Cancels the count.</param>
    /// <returns>The count.</returns>
    ValueTask<long> CountAsync(CancellationToken cancellationToken = default);

    /// <summary>One page of at most <paramref name="limit" /> entities.</summary>
    /// <param name="limit">The page size; zero gives an empty terminal page, a negative one throws.</param>
    /// <param name="after">The cursor the previous page returned.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The page.</returns>
    ValueTask<Page<T>> PageAsync(int limit, ICursor? after = null, CancellationToken cancellationToken = default);

    /// <summary>
    /// Hydrate everything that matches. Explicit because it is unbounded; reach for <see cref="PageAsync" />
    /// unless the set is known small.
    /// </summary>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>Every match.</returns>
    ValueTask<IReadOnlyList<T>> AllAsync(CancellationToken cancellationToken = default);

    /// <summary>The first match, if any.</summary>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The entity, or <see langword="null" />.</returns>
    ValueTask<T?> FirstAsync(CancellationToken cancellationToken = default);

    /// <summary>Whether anything matches.</summary>
    /// <param name="cancellationToken">Cancels the count.</param>
    /// <returns><see langword="true" /> when something does.</returns>
    ValueTask<bool> ExistsAsync(CancellationToken cancellationToken = default);
}
