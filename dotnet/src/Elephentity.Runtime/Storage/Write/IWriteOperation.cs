using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Storage.Write;

/// <summary>One row-level change within a batch.</summary>
public interface IWriteOperation
{
    /// <summary>The entity the change is to.</summary>
    string Entity { get; }

    /// <summary>The row the change is to.</summary>
    IIdentifier Target { get; }
}
