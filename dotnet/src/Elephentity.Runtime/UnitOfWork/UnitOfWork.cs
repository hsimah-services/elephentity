using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.SideEffect;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Commits mutations and deletions together.
/// </summary>
/// <remarks>
/// Commit order: pre-commit side effects, stamp managed fields, verify, encode, transactional writes, post-commit
/// side effects. Storage failures roll back; post-commit writes are independent.
/// </remarks>
public sealed class UnitOfWork
{
    private readonly IStorageAdaptor storage;
    private readonly VerificationPipeline verification;
    private readonly ValueEncoder encoder;
    private readonly SideEffectDispatcher sideEffects;
    private readonly ManagedFields managed;
    private readonly DeletionPlanner? planner;
    private readonly UniquenessCheck? unique;

    private List<Mutation.Mutation> mutations = [];
    private List<Deletion> deletions = [];

    /// <summary>A unit of work over its collaborators.</summary>
    /// <param name="storage">Where the writes go.</param>
    /// <param name="verification">Verifies each mutation.</param>
    /// <param name="encoder">Encodes values for storage.</param>
    /// <param name="sideEffects">Runs side effects around the commit.</param>
    /// <param name="managed">The fields the framework fills; none when omitted.</param>
    /// <param name="planner">Plans deletions. Absent when a project has none to plan, since it needs the edge graph.</param>
    /// <param name="unique">
    /// Checks unique fields before the transaction opens. Absent when nothing is unique, or when a caller does not
    /// want the extra read; the index remains the guarantee either way.
    /// </param>
    public UnitOfWork(
        IStorageAdaptor storage,
        VerificationPipeline verification,
        ValueEncoder encoder,
        SideEffectDispatcher sideEffects,
        ManagedFields? managed = null,
        DeletionPlanner? planner = null,
        UniquenessCheck? unique = null)
    {
        this.storage = storage;
        this.verification = verification;
        this.encoder = encoder;
        this.sideEffects = sideEffects;
        this.managed = managed ?? new ManagedFields();
        this.planner = planner;
        this.unique = unique;
    }

    /// <summary>Adds a mutation to the commit.</summary>
    /// <param name="mutation">The mutation.</param>
    public void Register(Mutation.Mutation mutation) => mutations.Add(mutation);

    /// <summary>Adds a deletion, and whatever its edges say goes with it.</summary>
    /// <param name="deletion">The row to remove.</param>
    public void Delete(Deletion deletion) => deletions.Add(deletion);

    /// <summary>Whether committing would do nothing.</summary>
    /// <returns><see langword="true" /> when nothing is registered that changes anything.</returns>
    public bool IsEmpty() => Pending().Count == 0 && deletions.Count == 0;

    /// <summary>Runs the commit.</summary>
    /// <param name="cancellationToken">Cancels the commit.</param>
    /// <returns>What the writes produced, chiefly the ids assigned to created rows.</returns>
    /// <exception cref="CommitRejectedException">Verification failed; nothing was written.</exception>
    public async Task<WriteResult> CommitAsync(CancellationToken cancellationToken = default)
    {
        var registered = mutations;
        var removing = deletions;

        if (registered.Count == 0 && removing.Count == 0)
        {
            return new WriteResult();
        }

        await sideEffects.DispatchAsync(SideEffectPhase.PreCommit, registered, cancellationToken).ConfigureAwait(false);

        var changed = Pending();

        if (changed.Count == 0 && removing.Count == 0)
        {
            var completed = mutations;
            mutations = [];
            await sideEffects.DispatchAsync(SideEffectPhase.PostCommit, completed, cancellationToken).ConfigureAwait(false);

            return new WriteResult();
        }

        // Plan removals before custom logic, then verify that the plan still holds inside the transaction. No
        // side effect is invoked after writes begin.
        var plannedRemovals = await DeletionOperationsAsync(removing, cancellationToken).ConfigureAwait(false);
        var removed = DeletionContexts(plannedRemovals);
        await sideEffects.DispatchDeletionsAsync(SideEffectPhase.PreCommit, removed, cancellationToken).ConfigureAwait(false);

        var deletionChanges = removed.Where(mutation => !mutation.IsEmpty()).ToList();
        List<Mutation.Mutation> allChanges = [.. changed, .. deletionChanges];
        var now = DateTimeOffset.UtcNow;

        foreach (var mutation in allChanges)
        {
            managed.Stamp(mutation, now);
        }

        await VerifyAsync(allChanges, cancellationToken).ConfigureAwait(false);

        var ordered = DependencySorter.Sort(allChanges);
        var rows = RowOperations(ordered);

        var result = await storage.TransactionAsync(
            async token =>
            {
                var written = await storage.WriteAsync(new WriteBatch([.. rows]), token).ConfigureAwait(false);
                var links = LinkOperations(ordered, written);

                if (links.Count > 0)
                {
                    await storage.WriteAsync(new WriteBatch([.. links]), token).ConfigureAwait(false);
                }

                var removals = await DeletionOperationsAsync(removing, token).ConfigureAwait(false);

                if (!removals.SequenceEqual(plannedRemovals))
                {
                    throw new InvalidOperationException(
                        "The deletion graph changed during this mutation. Retry against the current state.");
                }

                if (removals.Count > 0)
                {
                    await storage.WriteAsync(new WriteBatch([.. removals]), token).ConfigureAwait(false);
                }

                return written;
            },
            cancellationToken).ConfigureAwait(false);

        // Only after the transaction holds: a rolled-back insert has no id to hand out.
        ResolveIds(ordered, result);
        mutations = [];
        deletions = [];

        await sideEffects.DispatchAsync(SideEffectPhase.PostCommit, changed, cancellationToken).ConfigureAwait(false);
        await sideEffects.DispatchDeletionsAsync(SideEffectPhase.PostCommit, removed, cancellationToken).ConfigureAwait(false);

        return result;
    }

    /// <summary>
    /// One context per row the plan removes, in the order the plan removes them. A deletion carries no pending
    /// values, so the context holds identity and nothing else; a handler that needs the row reads it, which is
    /// why the pre-commit pass runs before the delete rather than after.
    /// </summary>
    private static List<Mutation.Mutation> DeletionContexts(IReadOnlyList<IWriteOperation> removals) =>
        [.. removals
            .OfType<Delete>()
            .Select(operation => new Mutation.Mutation(operation.Entity, operation.Id))];

    private async Task VerifyAsync(IReadOnlyList<Mutation.Mutation> changes, CancellationToken cancellationToken)
    {
        var violations = new List<FieldViolation>();

        foreach (var mutation in changes)
        {
            violations.AddRange(await verification.VerifyAsync(mutation, cancellationToken).ConfigureAwait(false));

            if (unique is not null)
            {
                violations.AddRange(await unique.CheckAsync(mutation, cancellationToken).ConfigureAwait(false));
            }
        }

        if (violations.Count > 0)
        {
            throw new CommitRejectedException(violations);
        }
    }

    private List<IWriteOperation> RowOperations(IReadOnlyList<Mutation.Mutation> ordered)
    {
        var operations = new List<IWriteOperation>();

        foreach (var mutation in ordered)
        {
            var values = new Dictionary<string, object?>();

            foreach (var (field, value) in mutation.Changes())
            {
                values[field] = encoder.Encode(mutation.Entity, field, value);
            }

            switch (mutation.Target)
            {
                case PendingId pending:
                    operations.Add(new Insert(mutation.Entity, pending, values));
                    break;
                case EntityId id when values.Count > 0:
                    operations.Add(new Update(mutation.Entity, id, values));
                    break;
            }
        }

        return operations;
    }

    /// <summary>Links are written after rows, with every pending id replaced by the real one.</summary>
    private static List<IWriteOperation> LinkOperations(IReadOnlyList<Mutation.Mutation> ordered, WriteResult result)
    {
        var operations = new List<IWriteOperation>();

        foreach (var mutation in ordered)
        {
            var from = Resolve(mutation.Target, result);

            foreach (var edge in mutation.EdgeChanges().Values)
            {
                if (edge.IsReplacement())
                {
                    // Clear first: a replacement says what the edge holds, not what to add to it.
                    operations.Add(new Unlink(mutation.Entity, edge.Name, from));
                }

                foreach (var target in edge.Removed())
                {
                    operations.Add(new Unlink(mutation.Entity, edge.Name, from, Resolve(target, result)));
                }

                foreach (var target in edge.Added())
                {
                    operations.Add(new Link(mutation.Entity, edge.Name, from, Resolve(target, result)));
                }
            }
        }

        return operations;
    }

    private async Task<IReadOnlyList<IWriteOperation>> DeletionOperationsAsync(
        List<Deletion> removing,
        CancellationToken cancellationToken)
    {
        if (removing.Count == 0)
        {
            return [];
        }

        if (planner is null)
        {
            throw new InvalidOperationException(
                "This unit of work was built without a DeletionPlanner, so it cannot delete. "
                + "Supply one, or do not register deletions.");
        }

        return await planner.PlanAsync(removing, cancellationToken).ConfigureAwait(false);
    }

    private static IIdentifier Resolve(IIdentifier identifier, WriteResult result) =>
        identifier is PendingId pending && result.WasAssigned(pending) ? result.IdFor(pending) : identifier;

    private static void ResolveIds(IReadOnlyList<Mutation.Mutation> ordered, WriteResult result)
    {
        foreach (var mutation in ordered)
        {
            if (mutation.Target is PendingId pending && result.WasAssigned(pending))
            {
                mutation.ResolveId(result.IdFor(pending));
            }
        }
    }

    private List<Mutation.Mutation> Pending() => [.. mutations.Where(mutation => !mutation.IsEmpty())];
}
