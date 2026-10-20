using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Manifest;

/// <summary>
/// The physical layout, compiled.
/// </summary>
/// <remarks>
/// The adaptor needs each entity's property map, the unique keys, where every edge lives and which orderings to
/// index. All of it is derived from the IR, and the IR is a build-time artefact, so it is worked out once by
/// <c>eleph-gen-cosmos</c> and written down as code that builds this, exactly as the GraphQL surface is. Deriving it
/// per request would mean shipping the schema compiler to production.
/// </remarks>
public sealed record CosmosStorageManifest
{
    /// <summary>The default container name.</summary>
    public const string DefaultContainer = "elephentity";

    /// <summary>A manifest.</summary>
    /// <param name="partitionKey">
    /// The project's logical partition: the value of <c>pk</c> in every document. One partition per project, so one
    /// <c>TransactionalBatch</c> can touch anything the project owns.
    /// </param>
    /// <param name="entities">Entity name to layout.</param>
    /// <param name="orderings">The orderings to index.</param>
    /// <param name="container">The container's name.</param>
    /// <exception cref="ArgumentException">The manifest contradicts itself.</exception>
    public CosmosStorageManifest(
        string partitionKey,
        IReadOnlyDictionary<string, CosmosEntityLayout> entities,
        IReadOnlyList<CosmosOrdering>? orderings = null,
        string container = DefaultContainer)
    {
        if (string.IsNullOrWhiteSpace(partitionKey))
        {
            throw new ArgumentException("A manifest needs a partition key value.", nameof(partitionKey));
        }

        PartitionKey = partitionKey;
        Entities = entities;
        Orderings = orderings ?? [];
        Container = container;

        foreach (var ordering in Orderings)
        {
            if (!entities.TryGetValue(ordering.Entity, out var layout))
            {
                throw new ArgumentException($"An ordering names {ordering.Entity}, which the manifest does not lay out.", nameof(orderings));
            }

            foreach (var term in ordering.Terms)
            {
                _ = layout.Property(term.Field);
            }
        }

        foreach (var layout in entities.Values)
        {
            foreach (var placement in layout.Edges.Values)
            {
                if (!entities.ContainsKey(placement.Target))
                {
                    throw new ArgumentException(
                        $"{layout.Entity}.{placement.Edge} points at {placement.Target}, which the manifest does not lay out.",
                        nameof(entities));
                }
            }
        }

        CheckReferencesStoredOnTargets(entities);
    }

    /// <summary>
    /// A one-to-many edge keeps its owner's id on each <em>target</em> document, so the property it uses has to be
    /// free there: the target's own layout cannot know about it.
    /// </summary>
    private static void CheckReferencesStoredOnTargets(IReadOnlyDictionary<string, CosmosEntityLayout> entities)
    {
        var claimed = new Dictionary<(string Entity, string Property), string>();

        foreach (var layout in entities.Values)
        {
            foreach (var placement in layout.Edges.Values.Where(placement => placement.Relation == RelationKind.OneToMany))
            {
                var by = $"edge {layout.Entity}.{placement.Edge}";
                var target = entities[placement.Target];

                if (!DocumentProperties.IsPlainName(placement.Property) || DocumentProperties.IsReserved(placement.Property))
                {
                    throw new ArgumentException($"\"{placement.Property}\" ({by}) is not a usable property name.");
                }

                if (target.Fields.Values.Contains(placement.Property))
                {
                    throw new ArgumentException($"\"{placement.Property}\" ({by}) is also a field of {target.Entity}.");
                }

                if (target.Edges.Values.Any(other => other.KeyIsLocal && other.Property == placement.Property))
                {
                    throw new ArgumentException($"\"{placement.Property}\" ({by}) is also an edge reference of {target.Entity}.");
                }

                if (!claimed.TryAdd((placement.Target, placement.Property), by))
                {
                    throw new ArgumentException(
                        $"\"{placement.Property}\" on {placement.Target} is claimed by both {claimed[(placement.Target, placement.Property)]} and {by}.");
                }
            }
        }
    }

    /// <summary>The project's logical partition value.</summary>
    public string PartitionKey { get; }

    /// <summary>Entity name to layout.</summary>
    public IReadOnlyDictionary<string, CosmosEntityLayout> Entities { get; }

    /// <summary>The orderings to index.</summary>
    public IReadOnlyList<CosmosOrdering> Orderings { get; }

    /// <summary>The container's name.</summary>
    public string Container { get; }

    /// <summary>
    /// How many synthetic unique-key properties every document carries: the most unique fields any entity has.
    /// </summary>
    /// <remarks>
    /// A missing property counts as a value for a unique key, so two documents without <c>_u0</c> would collide.
    /// Every document therefore carries every slot, filled with a value only it can have where it has no field there.
    /// </remarks>
    public int UniqueSlots => Entities.Count == 0 ? 0 : Entities.Values.Max(layout => layout.UniqueKeys.Count);

    /// <summary>The layout of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The layout.</returns>
    /// <exception cref="InvalidOperationException">The manifest does not lay it out.</exception>
    public CosmosEntityLayout Entity(string entity) =>
        Entities.TryGetValue(entity, out var layout)
            ? layout
            : throw new InvalidOperationException($"No layout is mapped for entity \"{entity}\".");

    /// <summary>Where <c>entity.edge</c> lives.</summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="edge">The edge.</param>
    /// <returns>The placement.</returns>
    /// <exception cref="InvalidOperationException">The edge is not mapped.</exception>
    public CosmosEdgePlacement Edge(string entity, string edge) =>
        Entity(entity).Edges.TryGetValue(edge, out var placement)
            ? placement
            : throw new InvalidOperationException($"Edge {entity}.{edge} is not mapped.");
}
