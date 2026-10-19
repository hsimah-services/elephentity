namespace Elephentity.Runtime.Storage;

/// <summary>
/// What depends on each entity.
/// </summary>
/// <remarks>Supplied by generated code, since the edge graph is a build-time fact.</remarks>
public interface IDeletionRules
{
    /// <summary>The rules that apply when a row of <paramref name="entity" /> is deleted.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The rules, in declaration order.</returns>
    IReadOnlyList<DeletionRule> RulesFor(string entity);
}
