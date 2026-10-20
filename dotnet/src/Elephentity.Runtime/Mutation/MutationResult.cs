using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Mutation;

/// <summary>A completed mutation. A null entity means the result is no longer visible.</summary>
/// <param name="Id">The row's id.</param>
/// <param name="Entity">The row as it is now, or <see langword="null" /> when the viewer may not read it.</param>
public sealed record MutationResult(EntityId Id, object? Entity);
