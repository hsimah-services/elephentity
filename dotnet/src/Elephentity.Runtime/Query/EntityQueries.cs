using Elephentity.Runtime.Storage;

namespace Elephentity.Runtime.Query;

/// <summary>
/// A typed view over an untyped query.
/// </summary>
/// <remarks>
/// The edge loader works by entity name and so hands back <see cref="IEntityQuery{T}" /> of <see cref="object" />; a generated
/// entity knows what its edge returns and wants the exact type. <see cref="IEntityQuery{T}" /> cannot be made covariant (its
/// results are <see cref="ValueTask{TResult}" />, which is not), so this wraps rather than converts. Nothing is read until the
/// view is asked for something, as with the query underneath.
/// </remarks>
public static class EntityQueries
{
    /// <summary>The query's entities as <typeparamref name="T" />.</summary>
    /// <typeparam name="T">The entity type the query's hydrator builds.</typeparam>
    /// <param name="query">The untyped query.</param>
    /// <returns>A query over the same set.</returns>
    /// <exception cref="InvalidCastException">An entity is not a <typeparamref name="T" />; thrown when it is read.</exception>
    public static IEntityQuery<T> Cast<T>(this IEntityQuery<object> query)
        where T : class
    {
        ArgumentNullException.ThrowIfNull(query);

        return new CastQuery<T>(query);
    }

    /// <summary>A typed query as the untyped one the gateway speaks.</summary>
    /// <typeparam name="T">The entity type.</typeparam>
    /// <param name="query">The typed query.</param>
    /// <returns>A query over the same set, whose rows are <see cref="object" />.</returns>
    /// <remarks>
    /// The inverse of <see cref="Cast{T}" />, for the places that hold an entity's type without naming it: an edge connection in
    /// GraphQL, or a declared query the catalogue hands back through <c>object?</c>.
    /// </remarks>
    public static IEntityQuery<object> AsObjects<T>(this IEntityQuery<T> query)
        where T : class
    {
        ArgumentNullException.ThrowIfNull(query);

        return query is IEntityQuery<object> already ? already : new ObjectQuery<T>(query);
    }

    private sealed class CastQuery<T>(IEntityQuery<object> inner) : IEntityQuery<T>
        where T : class
    {
        public ValueTask<long> CountAsync(CancellationToken cancellationToken = default) => inner.CountAsync(cancellationToken);

        public async ValueTask<Page<T>> PageAsync(int limit, ICursor? after = null, CancellationToken cancellationToken = default)
        {
            var page = await inner.PageAsync(limit, after, cancellationToken).ConfigureAwait(false);

            return new Page<T>([.. page.Items.Cast<T>()], page.Next);
        }

        public async ValueTask<IReadOnlyList<T>> AllAsync(CancellationToken cancellationToken = default) =>
            [.. (await inner.AllAsync(cancellationToken).ConfigureAwait(false)).Cast<T>()];

        public async ValueTask<T?> FirstAsync(CancellationToken cancellationToken = default) =>
            (T?)await inner.FirstAsync(cancellationToken).ConfigureAwait(false);

        public ValueTask<bool> ExistsAsync(CancellationToken cancellationToken = default) => inner.ExistsAsync(cancellationToken);
    }

    private sealed class ObjectQuery<T>(IEntityQuery<T> inner) : IEntityQuery<object>
        where T : class
    {
        public ValueTask<long> CountAsync(CancellationToken cancellationToken = default) => inner.CountAsync(cancellationToken);

        public async ValueTask<Page<object>> PageAsync(int limit, ICursor? after = null, CancellationToken cancellationToken = default)
        {
            var page = await inner.PageAsync(limit, after, cancellationToken).ConfigureAwait(false);

            return new Page<object>([.. page.Items], page.Next);
        }

        public async ValueTask<IReadOnlyList<object>> AllAsync(CancellationToken cancellationToken = default) =>
            [.. await inner.AllAsync(cancellationToken).ConfigureAwait(false)];

        public async ValueTask<object?> FirstAsync(CancellationToken cancellationToken = default) =>
            await inner.FirstAsync(cancellationToken).ConfigureAwait(false);

        public ValueTask<bool> ExistsAsync(CancellationToken cancellationToken = default) => inner.ExistsAsync(cancellationToken);
    }
}
