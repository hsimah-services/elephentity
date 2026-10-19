namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Orders mutations so that a row is written before anything that references it.
/// </summary>
/// <remarks>
/// This exists because ids are server-generated. A commit creating a Post and its Comments must insert the
/// Post, read back its id, and only then write the Comments, so the batch must arrive already sorted and the
/// adaptor never has to reason about order. The sort is stable: mutations with no relationship keep the order
/// they were registered in, so a commit's writes are reproducible and diffable in a log.
/// </remarks>
public static class DependencySorter
{
    /// <summary>Sorts <paramref name="mutations" /> so dependencies come first.</summary>
    /// <param name="mutations">The mutations, in registration order.</param>
    /// <returns>The same mutations, ordered.</returns>
    /// <exception cref="InvalidOperationException">There is a dependency cycle, which no ordering can satisfy.</exception>
    public static IReadOnlyList<Mutation.Mutation> Sort(IReadOnlyList<Mutation.Mutation> mutations)
    {
        var byTarget = new Dictionary<string, Mutation.Mutation>();

        foreach (var mutation in mutations)
        {
            byTarget[mutation.Target.ToString()!] = mutation;
        }

        var sorted = new Dictionary<string, Mutation.Mutation>();
        var state = new Dictionary<string, bool>();

        foreach (var mutation in mutations)
        {
            Visit(mutation, byTarget, sorted, state, []);
        }

        return [.. sorted.Values];
    }

    private static void Visit(
        Mutation.Mutation mutation,
        Dictionary<string, Mutation.Mutation> byTarget,
        Dictionary<string, Mutation.Mutation> sorted,
        Dictionary<string, bool> state,
        List<string> trail)
    {
        var key = mutation.Target.ToString()!;

        if (state.TryGetValue(key, out var done))
        {
            if (!done)
            {
                throw new InvalidOperationException(
                    $"These writes depend on each other and cannot be ordered: {string.Join(" → ", [.. trail, key])}.");
            }

            return;
        }

        state[key] = false;

        foreach (var dependency in mutation.Dependencies())
        {
            if (!byTarget.TryGetValue(dependency.ToString()!, out var required))
            {
                // A link to a pending row nothing in this commit creates. The commit cannot resolve it, so
                // say so here rather than writing a null column.
                throw new InvalidOperationException(
                    $"{mutation.Entity} links to {dependency}, which no mutation in this commit creates.");
            }

            Visit(required, byTarget, sorted, state, [.. trail, key]);
        }

        state[key] = true;
        sorted[key] = mutation;
    }
}
