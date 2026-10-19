using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>
/// A row this commit will remove.
/// </summary>
/// <remarks>
/// Deliberately not a <see cref="Mutation" />. A mutation carries pending field values and is verified; a
/// deletion carries neither, and pretending otherwise would mean every verifier had to ask whether the row
/// it was checking was about to cease existing.
/// </remarks>
/// <param name="Entity">The entity.</param>
/// <param name="Id">The row's id.</param>
public sealed record Deletion(string Entity, EntityId Id)
{
    /// <summary>A key unique to the row across entities, for de-duplicating.</summary>
    /// <returns><c>Entity#id</c>.</returns>
    public string Key() => $"{Entity}#{Id}";
}
