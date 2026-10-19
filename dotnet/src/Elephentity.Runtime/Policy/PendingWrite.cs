using Elephentity.Runtime.Mutation;

namespace Elephentity.Runtime.Policy;

/// <summary>A write that has been asked for and not yet applied: what a write policy decides about.</summary>
public sealed class PendingWrite : IWriteContext
{
    private static readonly IReadOnlyDictionary<string, object?> NoArguments = new Dictionary<string, object?>();

    private PendingWrite(
        string entity,
        WriteOperation operation,
        string? action,
        IReadOnlyDictionary<string, object?> arguments,
        IMutationContext? mutation)
    {
        Entity = entity;
        Operation = operation;
        Action = action;
        Arguments = arguments;
        Mutation = mutation;
    }

    /// <inheritdoc />
    public string Entity { get; }

    /// <inheritdoc />
    public WriteOperation Operation { get; }

    /// <inheritdoc />
    public IReadOnlyList<ActionCall> Actions => Mutation?.Actions ?? [];

    /// <inheritdoc />
    public string? Action { get; }

    /// <inheritdoc />
    public IReadOnlyDictionary<string, object?> Arguments { get; }

    /// <inheritdoc />
    public IMutationContext? Mutation { get; }

    /// <summary>A row is being created.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="mutation">The mutation.</param>
    /// <param name="arguments">The arguments of the write.</param>
    /// <returns>The pending write.</returns>
    public static PendingWrite Create(
        string entity,
        IMutationContext mutation,
        IReadOnlyDictionary<string, object?>? arguments = null) =>
        new(entity, WriteOperation.Create, null, arguments ?? NoArguments, mutation);

    /// <summary>A row is being changed.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="mutation">The mutation.</param>
    /// <param name="arguments">The arguments of the write.</param>
    /// <returns>The pending write.</returns>
    public static PendingWrite Update(
        string entity,
        IMutationContext mutation,
        IReadOnlyDictionary<string, object?>? arguments = null) =>
        new(entity, WriteOperation.Update, null, arguments ?? NoArguments, mutation);

    /// <summary>A row is being removed.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The pending write.</returns>
    public static PendingWrite Delete(string entity) =>
        new(entity, WriteOperation.Delete, null, NoArguments, null);

    /// <summary>A declared action is being run.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="action">The action's name.</param>
    /// <param name="arguments">The action's arguments.</param>
    /// <param name="mutation">The mutation.</param>
    /// <returns>The pending write.</returns>
    public static PendingWrite ForAction(
        string entity,
        string action,
        IReadOnlyDictionary<string, object?> arguments,
        IMutationContext mutation) =>
        new(entity, WriteOperation.Action, action, arguments, mutation);
}
