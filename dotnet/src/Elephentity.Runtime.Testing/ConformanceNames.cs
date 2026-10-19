namespace Elephentity.Runtime.Testing;

/// <summary>
/// The entity and edge names a conformance run writes to.
/// </summary>
/// <remarks>
/// Every check uses names the caller supplies, never hardcoded ones: a schema-free adaptor such as the memory
/// one accepts anything, but a real backend may need a name to exist already in whatever it was configured with.
/// </remarks>
/// <param name="Entity">An entity with <c>name</c> and <c>rank</c> fields, which the run seeds and removes.</param>
/// <param name="Edge">An edge declared by <paramref name="Entity" /> that points at <paramref name="Related" />.</param>
/// <param name="Related">An entity with a <c>name</c> field, the edge's target.</param>
/// <param name="SharedTargets">Whether the edge lets two owners share one target; enables the shared-target case.</param>
public sealed record ConformanceNames(string Entity, string Edge, string Related, bool SharedTargets = false)
{
    /// <summary>Names a schema-free adaptor needs no setup for.</summary>
    public static ConformanceNames Default { get; } = new("ConformanceEntity", "related", "ConformanceRelated", SharedTargets: true);
}
