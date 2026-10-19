namespace Elephentity.Runtime.Policy;

/// <summary>What kind of write a policy is deciding about.</summary>
public enum WriteOperation
{
    /// <summary>A row is being created.</summary>
    Create,

    /// <summary>A row is being changed.</summary>
    Update,

    /// <summary>A row is being removed.</summary>
    Delete,

    /// <summary>A declared action is being run.</summary>
    Action,
}

/// <summary>The wire spelling of a <see cref="WriteOperation" />.</summary>
public static class WriteOperationExtensions
{
    /// <summary>The spelling used in manifests: <c>create</c>, <c>update</c>, <c>delete</c> or <c>action</c>.</summary>
    /// <param name="operation">The operation.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this WriteOperation operation) => operation switch
    {
        WriteOperation.Create => "create",
        WriteOperation.Update => "update",
        WriteOperation.Delete => "delete",
        WriteOperation.Action => "action",
        _ => throw new ArgumentOutOfRangeException(nameof(operation)),
    };

    /// <summary>The operation a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The operation.</returns>
    /// <exception cref="ArgumentException">The name is not an operation.</exception>
    public static WriteOperation FromWire(string value) => value switch
    {
        "create" => WriteOperation.Create,
        "update" => WriteOperation.Update,
        "delete" => WriteOperation.Delete,
        "action" => WriteOperation.Action,
        _ => throw new ArgumentException($"\"{value}\" is not a write operation.", nameof(value)),
    };
}
