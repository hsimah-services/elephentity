using Elephentity.Runtime.Identity;

namespace Elephentity.Cosmos;

/// <summary>
/// Makes the ids of new documents.
/// </summary>
/// <remarks>
/// A time-ordered UUID (version 7) in its 32-hex-digit form. The leading bits are a millisecond timestamp, so ids
/// sort roughly in the order they were made, as SQLite's integer ids do, and that is what keeps the
/// <c>id ASC</c> tie-break that every page ends with meaningful: rows sharing a sort value come back oldest first.
/// The text form has no dashes, so its lexical order is the byte order. Ids are made by the adaptor, not by Cosmos, which
/// is what lets a unit of work know an inserted row's id before anything is written.
/// </remarks>
public static class CosmosIds
{
    /// <summary>A new id.</summary>
    /// <returns>The id, as a string <see cref="EntityId" />.</returns>
    public static EntityId New() => EntityId.Of(Guid.CreateVersion7().ToString("N"));
}
