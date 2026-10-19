namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// One entity as a GraphQL object type.
/// </summary>
/// <remarks>Ported from <c>Eleph\GraphQL\Manifest\ObjectTypeEntry</c>, plus <see cref="IsInstance" />.</remarks>
public sealed class ObjectTypeEntry
{
    /// <summary>Creates an entry.</summary>
    /// <param name="name">The GraphQL type name.</param>
    /// <param name="entity">The entity it shows.</param>
    /// <param name="fields">Its fields by GraphQL name.</param>
    /// <param name="isInstance">
    /// Whether a value is this entity's read model. The <c>Node</c> interface resolves a value to its object type with this, as the
    /// PHP application did with <c>instanceof</c>, and a delegate keeps it free of reflection.
    /// </param>
    /// <param name="connections">Its to-many edges, by GraphQL name.</param>
    /// <param name="description">The description, or <see langword="null" />.</param>
    /// <param name="interfaces">The interfaces it implements. Only <c>Node</c> exists, and every entity is one.</param>
    public ObjectTypeEntry(
        string name,
        string entity,
        IReadOnlyDictionary<string, FieldEntry> fields,
        Func<object, bool> isInstance,
        IReadOnlyDictionary<string, ConnectionEntry>? connections = null,
        string? description = null,
        IReadOnlyList<string>? interfaces = null)
    {
        Name = name;
        Entity = entity;
        Fields = fields;
        IsInstance = isInstance;
        Connections = connections ?? new Dictionary<string, ConnectionEntry>();
        Description = description;
        Interfaces = interfaces ?? ["Node"];
    }

    /// <summary>The GraphQL type name.</summary>
    public string Name { get; }

    /// <summary>The entity it shows.</summary>
    public string Entity { get; }

    /// <summary>Its fields by GraphQL name.</summary>
    public IReadOnlyDictionary<string, FieldEntry> Fields { get; }

    /// <summary>Whether a value is this entity's read model.</summary>
    public Func<object, bool> IsInstance { get; }

    /// <summary>Its to-many edges, by GraphQL name.</summary>
    public IReadOnlyDictionary<string, ConnectionEntry> Connections { get; }

    /// <summary>The description, or <see langword="null" />.</summary>
    public string? Description { get; }

    /// <summary>The interfaces it implements.</summary>
    public IReadOnlyList<string> Interfaces { get; }
}
