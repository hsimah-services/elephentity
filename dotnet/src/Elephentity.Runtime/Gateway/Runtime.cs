using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Microsoft.Extensions.Logging;

namespace Elephentity.Runtime.Gateway;

/// <summary>
/// The assembled framework, and the one thing an application holds.
/// </summary>
/// <remarks>
/// <para>
/// Every layer below is separately testable and separately useless: an adaptor with no hydrators, a unit of work
/// with no verifiers. This is where they meet.
/// </para>
/// <para>
/// Each write commits. A protocol request is a transaction boundary, and holding a unit of work open across
/// requests would mean deciding when it closes. Loading an entity to write it skips the read gate, so write
/// policies see the original entity whatever the viewer may read.
/// </para>
/// </remarks>
public sealed class Runtime : IEntityGateway, IHydratorRegistry
{
    private readonly IStorageAdaptor storage;
    private readonly IEntityCatalogue catalogue;
    private readonly UnitOfWorkFactory units;
    private readonly ReadGate reads;
    private readonly WriteGate writes;
    private readonly ILogger? log;

    /// <summary>The assembled framework.</summary>
    /// <param name="storage">Where rows live.</param>
    /// <param name="catalogue">Everything generated from the spec.</param>
    /// <param name="units">Builds a unit of work per commit.</param>
    /// <param name="reads">Enforces read policies.</param>
    /// <param name="writes">Enforces write policies.</param>
    /// <param name="log">Where a restarted page is logged.</param>
    public Runtime(
        IStorageAdaptor storage,
        IEntityCatalogue catalogue,
        UnitOfWorkFactory units,
        ReadGate reads,
        WriteGate writes,
        ILogger? log = null)
    {
        this.storage = storage;
        this.catalogue = catalogue;
        this.units = units;
        this.reads = reads;
        this.writes = writes;
        this.log = log;
    }

    /// <inheritdoc />
    public async ValueTask<object?> FindAsync(string entity, EntityId id, CancellationToken cancellationToken = default)
    {
        var instance = await LoadAsync(entity, id, cancellationToken).ConfigureAwait(false);

        return instance is null
            ? null
            : await reads.PermitAsync(entity, instance, cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public IEntityQuery<object> All(string entity) =>
        new LazyEntityQuery<object>(storage, HydratorFor(entity), Edges(), Criteria.For(entity), reads, log);

    /// <inheritdoc />
    public ValueTask<object?> RunQueryAsync(
        string entity,
        string query,
        IReadOnlyDictionary<string, object?> args,
        CancellationToken cancellationToken = default)
    {
        // Arguments are positioned by the catalogue, which knows the query's parameter order; one the caller
        // omitted is null.
        var named = catalogue.QueryArguments(entity, query)
            .ToDictionary(name => name, name => args.GetValueOrDefault(name));

        return catalogue.RunQueryAsync(entity, query, named, cancellationToken);
    }

    /// <inheritdoc />
    public async Task<MutationResult> CreateAsync(
        string entity,
        IReadOnlyDictionary<string, object?> input,
        CancellationToken cancellationToken = default)
    {
        var target = new PendingId(entity);
        var mutation = new Mutation.Mutation(entity, target, actions: [new ActionCall("create", input)]);

        await writes.PermitAsync(entity, null, PendingWrite.Create(entity, mutation, input), cancellationToken).ConfigureAwait(false);
        catalogue.Apply(entity, mutation, input);

        var work = units.Create();
        work.Register(mutation);
        var result = await work.CommitAsync(cancellationToken).ConfigureAwait(false);

        return await ResultAsync(entity, result.IdFor(target), cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public async Task<MutationResult> UpdateAsync(
        string entity,
        EntityId id,
        IReadOnlyDictionary<string, object?> input,
        CancellationToken cancellationToken = default)
    {
        var existing = await RequireAsync(entity, id, cancellationToken).ConfigureAwait(false);
        var mutation = new Mutation.Mutation(
            entity,
            id,
            catalogue.ValuesOf(entity, existing),
            [new ActionCall("update", input)],
            existing);

        await writes.PermitAsync(entity, existing, PendingWrite.Update(entity, mutation, input), cancellationToken).ConfigureAwait(false);
        catalogue.Apply(entity, mutation, input);

        var work = units.Create();
        work.Register(mutation);
        await work.CommitAsync(cancellationToken).ConfigureAwait(false);

        return await ResultAsync(entity, id, cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public async Task DeleteAsync(string entity, EntityId id, CancellationToken cancellationToken = default)
    {
        var existing = await RequireAsync(entity, id, cancellationToken).ConfigureAwait(false);

        await writes.PermitAsync(entity, existing, PendingWrite.Delete(entity), cancellationToken).ConfigureAwait(false);

        var work = units.Create();
        work.Delete(new Deletion(entity, id));
        await work.CommitAsync(cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public Task<MutationResult> RunActionAsync(
        string entity,
        string action,
        EntityId id,
        IReadOnlyDictionary<string, object?> args,
        CancellationToken cancellationToken = default) =>
        RunActionsAsync(entity, id, [new ActionCall(action, args)], cancellationToken);

    /// <inheritdoc />
    public async Task<MutationResult> RunActionsAsync(
        string entity,
        EntityId id,
        IReadOnlyList<ActionCall> actions,
        CancellationToken cancellationToken = default)
    {
        if (actions.Count == 0)
        {
            throw new ArgumentException("A mutation must request at least one action.", nameof(actions));
        }

        var existing = await RequireAsync(entity, id, cancellationToken).ConfigureAwait(false);

        var decoded = actions
            .Select(action => new ActionCall(
                action.Name,
                catalogue.DecodeActionArguments(entity, action.Name, action.Arguments)))
            .ToList();

        var mutation = new Mutation.Mutation(entity, id, catalogue.ValuesOf(entity, existing), decoded, existing);

        // Every action is permitted before any runs, so a refusal leaves nothing half-applied.
        foreach (var action in decoded)
        {
            await writes.PermitAsync(
                entity,
                existing,
                PendingWrite.ForAction(entity, action.Name, action.Arguments, mutation),
                cancellationToken).ConfigureAwait(false);
        }

        foreach (var action in decoded)
        {
            await catalogue.RunActionAsync(entity, mutation, action, cancellationToken).ConfigureAwait(false);
        }

        var work = units.Create();
        work.Register(mutation);
        await work.CommitAsync(cancellationToken).ConfigureAwait(false);

        return await ResultAsync(entity, id, cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public bool Has(string entity) => catalogue.Entities().Contains(entity);

    /// <inheritdoc />
    public IHydrator<object> HydratorFor(string entity) => catalogue.Hydrator(entity);

    /// <summary>
    /// A query builder for hand-written finders. The application's own query classes need one, and building it
    /// means knowing which four things a lazy query is made of; this is the assembled framework, so it is the
    /// thing that already knows.
    /// </summary>
    /// <returns>The builder.</returns>
    public Queries Queries() => new(storage, Edges(), reads, log);

    private async Task<MutationResult> ResultAsync(string entity, EntityId id, CancellationToken cancellationToken)
    {
        var instance = await LoadAsync(entity, id, cancellationToken).ConfigureAwait(false);

        if (instance is not null)
        {
            try
            {
                instance = await reads.PermitAsync(entity, instance, cancellationToken).ConfigureAwait(false);
            }
            catch (AccessDeniedException)
            {
                // The write succeeded and the viewer may not read what it produced; the result says so by
                // carrying no entity.
                instance = null;
            }
        }

        return new MutationResult(id, instance);
    }

    private CachingEdgeLoader Edges() => new(storage, this, catalogue.EdgeTargets(), reads, log);

    private async Task<object> RequireAsync(string entity, EntityId id, CancellationToken cancellationToken) =>
        await LoadAsync(entity, id, cancellationToken).ConfigureAwait(false)
        ?? throw new EntityNotFoundException(entity, id);

    private async ValueTask<object?> LoadAsync(string entity, EntityId id, CancellationToken cancellationToken)
    {
        var record = await storage.GetAsync(entity, id, cancellationToken).ConfigureAwait(false);

        return record is null ? null : HydratorFor(entity).Hydrate(record, Edges());
    }
}
