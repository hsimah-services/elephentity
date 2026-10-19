namespace Elephentity.Runtime.Storage;

/// <summary>
/// One thing that must happen to dependents when an entity is deleted.
/// </summary>
/// <remarks>
/// Derived from an edge at build time, because the runtime has no schema: the generator knows
/// which edges point at what, and emits the answer rather than making the unit of work work it
/// out.
/// </remarks>
/// <param name="Dependent">The entity whose rows depend on the one being deleted.</param>
/// <param name="Edge">The edge these rows are reached through.</param>
/// <param name="DeclaredBy">The entity that declares that edge.</param>
/// <param name="Policy">What to do with the dependents.</param>
/// <param name="ViaJoinTable">
/// Whether the link lives in a join table. Join rows always go, whatever the policy: a link to a
/// row that no longer exists is not a policy choice. <see cref="DeletionPolicy.Cascade" />
/// additionally deletes the far side, which for shared vocabulary is rarely what anyone wants and
/// is exactly why it has to be asked for.
/// </param>
public sealed record DeletionRule(
    string Dependent,
    string Edge,
    string DeclaredBy,
    DeletionPolicy Policy,
    bool ViaJoinTable = false);
