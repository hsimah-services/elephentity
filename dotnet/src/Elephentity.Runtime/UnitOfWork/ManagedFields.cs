using Elephentity.Runtime.Mutation;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// The fields the framework fills, and the stamping of them.
/// </summary>
/// <remarks>
/// Runs before verification, not after, and that ordering is the whole point: a managed field that arrived at
/// the required check unset would be reported as missing, when in fact nobody was ever supposed to supply it.
/// Stamp first and every later stage sees a complete row. One instant for the whole commit, passed in rather
/// than read here, so a row created and immediately touched carries the same created and updated times: two
/// clock reads would differ by microseconds and make "never modified" impossible to test for.
/// </remarks>
public sealed class ManagedFields
{
    private readonly IReadOnlyDictionary<string, Managed> policies;

    /// <summary>The fields to fill.</summary>
    /// <param name="policies">"Entity.field" to when it is filled; none by default.</param>
    public ManagedFields(IReadOnlyDictionary<string, Managed>? policies = null)
    {
        this.policies = policies ?? new Dictionary<string, Managed>();
    }

    /// <summary>Whether no field is managed.</summary>
    /// <returns><see langword="true" /> when nothing is.</returns>
    public bool IsEmpty() => policies.Count == 0;

    /// <summary>Whether a field is the framework's to fill.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="field">The field.</param>
    /// <returns><see langword="true" /> when it is.</returns>
    public bool Has(string entity, string field) => policies.ContainsKey($"{entity}.{field}");

    /// <summary>
    /// Fill this mutation's managed fields.
    /// </summary>
    /// <remarks>
    /// Deliberately not called for a mutation that changes nothing: bumping the modified time on an entity
    /// nobody touched would turn every read-modify-nothing into a write.
    /// </remarks>
    /// <param name="mutation">The mutation.</param>
    /// <param name="now">The instant of the commit.</param>
    public void Stamp(Mutation.Mutation mutation, DateTimeOffset now)
    {
        var prefix = mutation.Entity + ".";

        foreach (var (key, policy) in policies)
        {
            if (!key.StartsWith(prefix, StringComparison.Ordinal))
            {
                continue;
            }

            if (policy == Managed.Created && !mutation.IsCreate)
            {
                continue;
            }

            mutation.Assign(key[prefix.Length..], now);
        }
    }
}
