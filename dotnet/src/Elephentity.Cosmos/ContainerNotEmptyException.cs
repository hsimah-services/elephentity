namespace Elephentity.Cosmos;

/// <summary>An operation that only makes sense on an empty container found documents in it.</summary>
/// <remarks>
/// Installing and importing are for a container with nothing in it. Neither will touch one that holds data: changing
/// the unique keys of a populated container, or loading a backup over live rows, is a migration, which is a different
/// operation with a different procedure (export, new container, import).
/// </remarks>
public sealed class ContainerNotEmptyException : InvalidOperationException
{
    /// <summary>An error for a container that is not empty.</summary>
    /// <param name="container">The container's name.</param>
    /// <param name="operation">What was refused.</param>
    public ContainerNotEmptyException(string container, string operation)
        : base($"The container \"{container}\" already holds documents, so {operation} was refused. It is for an empty container; to change a populated one, export it, create a new container and import into that (docs/cosmos.md).")
    {
        Container = container;
    }

    /// <summary>The container's name.</summary>
    public string Container { get; }
}
