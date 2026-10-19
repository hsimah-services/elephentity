using Elephentity.Runtime.Mutation;

namespace Elephentity.Runtime.Policy;

/// <summary>What a write policy is told about the write it is deciding.</summary>
public interface IWriteContext
{
    /// <summary>The entity being written.</summary>
    string Entity { get; }

    /// <summary>What kind of write it is.</summary>
    WriteOperation Operation { get; }

    /// <summary>The actions the mutation requests.</summary>
    IReadOnlyList<ActionCall> Actions { get; }

    /// <summary>The action being run, when <see cref="Operation" /> is <see cref="WriteOperation.Action" />.</summary>
    string? Action { get; }

    /// <summary>The arguments of the write.</summary>
    IReadOnlyDictionary<string, object?> Arguments { get; }

    /// <summary>The mutation, when there is one; a delete has none.</summary>
    IMutationContext? Mutation { get; }
}
