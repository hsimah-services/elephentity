namespace Elephentity.Runtime.Identity;

/// <summary>
/// A handle to an entity row, whether or not it exists yet.
/// </summary>
/// <remarks>
/// A unit of work that creates a Post and its Comments in one commit must be able to refer
/// to the Post before the database has assigned it an id. Both states therefore satisfy the
/// same interface, and it is the unit of work's job to substitute real ids once the writes
/// they depend on have been flushed.
/// </remarks>
public interface IIdentifier
{
    /// <summary>Whether this identifies a row that exists in storage.</summary>
    bool IsPersisted { get; }

    /// <summary>Whether <paramref name="other" /> identifies the same row.</summary>
    /// <param name="other">The identifier to compare with.</param>
    /// <returns><see langword="true" /> when both name the same row.</returns>
    bool Equals(IIdentifier? other);
}
