using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Query;

namespace Elephentity.Runtime.Gateway;

/// <summary>
/// Entities addressed by name rather than by type.
/// </summary>
/// <remarks>
/// <para>
/// Everything else in the framework is exactly typed, and deliberately so. A protocol layer cannot be: a GraphQL
/// resolver is handed the string "Item" and a map of arguments, and has no compile-time way to reach
/// <c>ItemFinder</c>. So it needs a facade that takes names, and this is it. That makes it the one place where
/// the type safety is given up on purpose. It is kept as small as the protocol layers actually need, and
/// everything behind it (hydration, verification, the unit of work) stays typed.
/// </para>
/// <para>
/// Every write commits. A protocol request is a transaction boundary; batching across requests would mean holding
/// a unit of work open across them.
/// </para>
/// </remarks>
public interface IEntityGateway
{
    /// <summary>One row, if it exists and the viewer may read it.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="id">The row's id.</param>
    /// <param name="cancellationToken">Cancels the read.</param>
    /// <returns>The entity, or <see langword="null" />.</returns>
    /// <exception cref="Policy.AccessDeniedException">A read policy refused.</exception>
    ValueTask<object?> FindAsync(string entity, EntityId id, CancellationToken cancellationToken = default);

    /// <summary>A lazy query over every row of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The query.</returns>
    IEntityQuery<object> All(string entity);

    /// <summary>
    /// Run a query the spec declared, by name. Collections return an <see cref="IEntityQuery{T}" />; singular
    /// queries return the entity or null.
    /// </summary>
    /// <param name="entity">The entity.</param>
    /// <param name="query">The query.</param>
    /// <param name="args">Argument name to value.</param>
    /// <param name="cancellationToken">Cancels the query.</param>
    /// <returns>The result.</returns>
    ValueTask<object?> RunQueryAsync(
        string entity,
        string query,
        IReadOnlyDictionary<string, object?> args,
        CancellationToken cancellationToken = default);

    /// <summary>Create a row.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="input">Field name to raw value; coercion is the implementation's job.</param>
    /// <param name="cancellationToken">Cancels the commit.</param>
    /// <returns>The new row.</returns>
    Task<MutationResult> CreateAsync(string entity, IReadOnlyDictionary<string, object?> input, CancellationToken cancellationToken = default);

    /// <summary>Change a row.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="id">The row's id.</param>
    /// <param name="input">Only the fields to change.</param>
    /// <param name="cancellationToken">Cancels the commit.</param>
    /// <returns>The row as it is now.</returns>
    Task<MutationResult> UpdateAsync(
        string entity,
        EntityId id,
        IReadOnlyDictionary<string, object?> input,
        CancellationToken cancellationToken = default);

    /// <summary>Remove a row, and whatever its edges say goes with it.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="id">The row's id.</param>
    /// <param name="cancellationToken">Cancels the commit.</param>
    /// <returns>A task that completes when the row is gone.</returns>
    Task DeleteAsync(string entity, EntityId id, CancellationToken cancellationToken = default);

    /// <summary>Run one action.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="action">The action.</param>
    /// <param name="id">The row's id.</param>
    /// <param name="args">The action's arguments.</param>
    /// <param name="cancellationToken">Cancels the commit.</param>
    /// <returns>The row as it is now.</returns>
    Task<MutationResult> RunActionAsync(
        string entity,
        string action,
        EntityId id,
        IReadOnlyDictionary<string, object?> args,
        CancellationToken cancellationToken = default);

    /// <summary>Run several actions in one commit.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="id">The row's id.</param>
    /// <param name="actions">The actions, at least one.</param>
    /// <param name="cancellationToken">Cancels the commit.</param>
    /// <returns>The row as it is now.</returns>
    Task<MutationResult> RunActionsAsync(
        string entity,
        EntityId id,
        IReadOnlyList<ActionCall> actions,
        CancellationToken cancellationToken = default);
}
