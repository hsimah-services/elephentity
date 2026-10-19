using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Turns "delete this row" into everything that entails.
/// </summary>
/// <remarks>
/// Walks the edges that point at the row, applies each policy, and recurses where one says cascade: a Post
/// taking its Comments with it, and each Comment taking whatever depends on <em>it</em>. Two things the walk has
/// to survive. Cycles: Post cascades to Comment, Comment cascades back to Post, and without a visited set that
/// runs until the stack gives out. And ordering: dependents are deleted before the row they depend on, so a
/// foreign key never dangles even briefly.
/// </remarks>
public sealed class DeletionPlanner
{
    private const int MaxDepth = 32;

    private readonly IStorageAdaptor storage;
    private readonly IDeletionRules rules;

    /// <summary>A planner over the project's edge graph.</summary>
    /// <param name="storage">Where dependents are found.</param>
    /// <param name="rules">What depends on each entity.</param>
    public DeletionPlanner(IStorageAdaptor storage, IDeletionRules rules)
    {
        this.storage = storage;
        this.rules = rules;
    }

    /// <summary>Plans the writes that carry out <paramref name="deletions" />.</summary>
    /// <param name="deletions">The rows to remove.</param>
    /// <param name="cancellationToken">Cancels the reads.</param>
    /// <returns>The write operations, dependents first.</returns>
    /// <exception cref="InvalidOperationException">A restrict rule is violated, or the graph is too deep.</exception>
    public async Task<IReadOnlyList<IWriteOperation>> PlanAsync(
        IReadOnlyList<Deletion> deletions,
        CancellationToken cancellationToken = default)
    {
        var operations = new List<IWriteOperation>();
        var visited = new HashSet<string>();

        foreach (var deletion in deletions)
        {
            operations.AddRange(await ExpandAsync(deletion, visited, 0, cancellationToken).ConfigureAwait(false));
        }

        return operations;
    }

    private async Task<List<IWriteOperation>> ExpandAsync(
        Deletion deletion,
        HashSet<string> visited,
        int depth,
        CancellationToken cancellationToken)
    {
        if (visited.Contains(deletion.Key()))
        {
            return [];
        }

        if (depth > MaxDepth)
        {
            throw new InvalidOperationException(
                $"Deleting {deletion.Key()} cascades more than {MaxDepth} levels deep. "
                + "That is almost certainly a policy loop rather than a real graph.");
        }

        visited.Add(deletion.Key());

        var operations = new List<IWriteOperation>();

        foreach (var rule in rules.RulesFor(deletion.Entity))
        {
            // Rules already select the dependent side. Deleting the declaring row (Post.comments) reads along;
            // deleting the target (Inventory.item) reads back to the declaring rows that depend on it.
            var reversed = deletion.Entity != rule.DeclaredBy;
            var link = reversed
                ? EdgeFilter.Back(rule.DeclaredBy, rule.Edge, deletion.Id)
                : EdgeFilter.Along(rule.DeclaredBy, rule.Edge, deletion.Id);
            var criteria = Criteria.For(rule.Dependent).LinkedTo(link);

            if (rule.Policy == DeletionPolicy.Restrict)
            {
                var remaining = await storage.CountAsync(criteria, cancellationToken).ConfigureAwait(false);

                if (remaining > 0)
                {
                    throw new InvalidOperationException(
                        $"Cannot delete {deletion.Key()}: {remaining} {rule.Dependent} still depend{(remaining == 1 ? "s" : string.Empty)} "
                        + $"on it through \"{rule.Edge}\". The edge says restrict.");
                }

                continue;
            }

            IReadOnlyList<EntityId>? dependents = null;

            // Unlink is always expressed from the declaring side, even when the deleted row is on the other end.
            // Use pairs in that direction so other links of a surviving dependent are preserved.
            if (rule.ViaJoinTable || rule.Policy == DeletionPolicy.Nullify)
            {
                if (reversed)
                {
                    dependents = await DependentsAsync(criteria, cancellationToken).ConfigureAwait(false);

                    foreach (var id in dependents)
                    {
                        operations.Add(new Unlink(rule.DeclaredBy, rule.Edge, id, deletion.Id));
                    }
                }
                else
                {
                    operations.Add(new Unlink(rule.DeclaredBy, rule.Edge, deletion.Id));
                }
            }

            if (rule.Policy == DeletionPolicy.Nullify)
            {
                continue;
            }

            dependents ??= await DependentsAsync(criteria, cancellationToken).ConfigureAwait(false);

            foreach (var id in dependents)
            {
                operations.AddRange(
                    await ExpandAsync(new Deletion(rule.Dependent, id), visited, depth + 1, cancellationToken)
                        .ConfigureAwait(false));
            }
        }

        // Last, so nothing is left pointing at a row that has already gone.
        operations.Add(new Delete(deletion.Entity, deletion.Id));

        return operations;
    }

    private async Task<IReadOnlyList<EntityId>> DependentsAsync(Criteria criteria, CancellationToken cancellationToken)
    {
        var page = await storage.QueryAsync(criteria.Unbounded(), cancellationToken).ConfigureAwait(false);

        return [.. page.Items.Select(record => record.Id)];
    }
}
