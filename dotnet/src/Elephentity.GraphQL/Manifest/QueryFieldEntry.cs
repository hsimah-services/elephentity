namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// A declared query published at the root.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\QueryFieldEntry</c>. Opt-in per query: an entity being in the graph does not mean every
/// finder it declares belongs in the public API.
/// </remarks>
public sealed class QueryFieldEntry
{
    /// <summary>Creates an entry.</summary>
    /// <param name="field">The root field's name.</param>
    /// <param name="type">The GraphQL type of the rows.</param>
    /// <param name="isCollection">Whether it is a connection (otherwise a single nullable row).</param>
    /// <param name="entity">The entity it queries.</param>
    /// <param name="query">The declared query's name.</param>
    /// <param name="args">Its arguments by name.</param>
    /// <param name="description">The description, or <see langword="null" />.</param>
    public QueryFieldEntry(
        string field,
        string type,
        bool isCollection,
        string entity,
        string query,
        IReadOnlyDictionary<string, GraphQLType>? args = null,
        string? description = null)
    {
        Field = field;
        Type = type;
        IsCollection = isCollection;
        Entity = entity;
        Query = query;
        Args = args ?? new Dictionary<string, GraphQLType>();
        Description = description;
    }

    /// <summary>The root field's name.</summary>
    public string Field { get; }

    /// <summary>The GraphQL type of the rows.</summary>
    public string Type { get; }

    /// <summary>Whether it is a connection.</summary>
    public bool IsCollection { get; }

    /// <summary>The entity it queries.</summary>
    public string Entity { get; }

    /// <summary>The declared query's name.</summary>
    public string Query { get; }

    /// <summary>Its arguments by name.</summary>
    public IReadOnlyDictionary<string, GraphQLType> Args { get; }

    /// <summary>The description, or <see langword="null" />.</summary>
    public string? Description { get; }
}
