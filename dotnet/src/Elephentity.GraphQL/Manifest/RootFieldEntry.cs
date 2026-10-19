namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// The root fields an exposed entity gets: one by id, one collection, and a delete.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\RootFieldEntry</c>. Both names are supplied rather than derived: the generator does not
/// pluralise anywhere.
/// </remarks>
/// <param name="Type">The GraphQL type name.</param>
/// <param name="Plural">The plural, for the collection.</param>
/// <param name="Entity">The entity.</param>
public sealed record RootFieldEntry(string Type, string Plural, string Entity)
{
    /// <summary>The single-row field: <c>clogItem(id: …)</c>.</summary>
    /// <returns>The type name with a lower-case first letter.</returns>
    public string ByIdField() => LowerFirst(Type);

    /// <summary>The collection field: <c>clogItems(first: …)</c>.</summary>
    /// <returns>The plural with a lower-case first letter.</returns>
    public string CollectionField() => LowerFirst(Plural);

    private static string LowerFirst(string name) => name.Length == 0 ? name : char.ToLowerInvariant(name[0]) + name[1..];
}
