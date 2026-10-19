namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// Everything the GraphQL layer registers, computed once at build time.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\Manifest</c>, renamed so it does not collide with the storage manifests. Data, not generated
/// resolver code and not a reflective walk of the IR on every boot.
/// </remarks>
public sealed record GraphQLManifest
{
    /// <summary>Creates a manifest.</summary>
    /// <param name="objects">The object types by GraphQL name.</param>
    /// <param name="enums">The enum types by name.</param>
    /// <param name="mutations">The mutations by name.</param>
    /// <param name="roots">The root fields by GraphQL type name.</param>
    /// <param name="queries">The published queries by field name.</param>
    public GraphQLManifest(
        IReadOnlyDictionary<string, ObjectTypeEntry>? objects = null,
        IReadOnlyDictionary<string, EnumTypeEntry>? enums = null,
        IReadOnlyDictionary<string, MutationEntry>? mutations = null,
        IReadOnlyDictionary<string, RootFieldEntry>? roots = null,
        IReadOnlyDictionary<string, QueryFieldEntry>? queries = null)
    {
        Objects = objects ?? new Dictionary<string, ObjectTypeEntry>();
        Enums = enums ?? new Dictionary<string, EnumTypeEntry>();
        Mutations = mutations ?? new Dictionary<string, MutationEntry>();
        Roots = roots ?? new Dictionary<string, RootFieldEntry>();
        Queries = queries ?? new Dictionary<string, QueryFieldEntry>();
    }

    /// <summary>The object types by GraphQL name.</summary>
    public IReadOnlyDictionary<string, ObjectTypeEntry> Objects { get; }

    /// <summary>The enum types by name.</summary>
    public IReadOnlyDictionary<string, EnumTypeEntry> Enums { get; }

    /// <summary>The mutations by name.</summary>
    public IReadOnlyDictionary<string, MutationEntry> Mutations { get; }

    /// <summary>The root fields by GraphQL type name.</summary>
    public IReadOnlyDictionary<string, RootFieldEntry> Roots { get; }

    /// <summary>The published queries by field name.</summary>
    public IReadOnlyDictionary<string, QueryFieldEntry> Queries { get; }

    /// <summary>Whether there is nothing to register.</summary>
    /// <returns><see langword="true" /> when there are no object types.</returns>
    public bool IsEmpty() => Objects.Count == 0;
}
