namespace Elephentity.Cosmos.Manifest;

/// <summary>
/// How one entity is laid out in documents.
/// </summary>
/// <remarks>
/// A field is a top-level property of the entity's document; the layout says which. It is a map rather than a rule
/// because a spec field may not be called what its property has to be: <c>id</c>, <c>pk</c> and <c>type</c> are the
/// container's, and every name starting with an underscore is Cosmos's or the unique-key slots'.
/// </remarks>
public sealed record CosmosEntityLayout
{
    /// <summary>A layout for <paramref name="entity" />.</summary>
    /// <param name="entity">The entity's name; also the document <c>type</c>.</param>
    /// <param name="fields">Spec field name to document property.</param>
    /// <param name="uniqueKeys">The unique fields, in slot order.</param>
    /// <param name="edges">Edge name to placement, for the edges this entity declares.</param>
    /// <exception cref="ArgumentException">A property name is reserved, repeated, or not a plain identifier.</exception>
    public CosmosEntityLayout(
        string entity,
        IReadOnlyDictionary<string, string> fields,
        IReadOnlyList<CosmosUniqueKey>? uniqueKeys = null,
        IReadOnlyDictionary<string, CosmosEdgePlacement>? edges = null)
    {
        Entity = entity;
        Fields = fields;
        UniqueKeys = uniqueKeys ?? [];
        Edges = edges ?? new Dictionary<string, CosmosEdgePlacement>();

        var taken = new Dictionary<string, string>(StringComparer.Ordinal);

        foreach (var (field, property) in fields)
        {
            Claim(taken, property, $"field {field}");
        }

        foreach (var placement in Edges.Values.Where(placement => !placement.UsesJoinDocuments && placement.KeyIsLocal))
        {
            Claim(taken, placement.Property, $"edge {placement.Edge}");
        }

        foreach (var key in UniqueKeys)
        {
            if (!fields.ContainsKey(key.Field))
            {
                throw new ArgumentException($"{entity} marks {key.Field} unique but declares no such field.", nameof(uniqueKeys));
            }
        }
    }

    /// <summary>The entity's name.</summary>
    public string Entity { get; }

    /// <summary>Spec field name to document property.</summary>
    public IReadOnlyDictionary<string, string> Fields { get; }

    /// <summary>The unique fields, in slot order: slot <c>n</c> is the document property <c>_un</c>.</summary>
    public IReadOnlyList<CosmosUniqueKey> UniqueKeys { get; }

    /// <summary>Edge name to placement, for the edges this entity declares.</summary>
    public IReadOnlyDictionary<string, CosmosEdgePlacement> Edges { get; }

    /// <summary>The document property that carries <paramref name="field" />; <c>id</c> is the document id.</summary>
    /// <param name="field">The spec field.</param>
    /// <returns>The property name.</returns>
    /// <exception cref="InvalidOperationException">The entity has no such field.</exception>
    public string Property(string field) =>
        field == "id"
            ? DocumentProperties.Id
            : Fields.TryGetValue(field, out var property)
                ? property
                : throw new InvalidOperationException($"{Entity} has no field \"{field}\" in the Cosmos manifest.");

    private static void Claim(Dictionary<string, string> taken, string property, string by)
    {
        if (!DocumentProperties.IsPlainName(property))
        {
            throw new ArgumentException(
                $"\"{property}\" ({by}) is not a usable property name: use letters, digits and underscores, not starting with a digit.");
        }

        if (DocumentProperties.IsReserved(property))
        {
            throw new ArgumentException($"\"{property}\" ({by}) is a name the container reserves.");
        }

        if (!taken.TryAdd(property, by))
        {
            throw new ArgumentException($"\"{property}\" is claimed by both {taken[property]} and {by}.");
        }
    }
}
