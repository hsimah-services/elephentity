using Elephentity.Runtime.Type;
using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Runs verification over a mutation and collects everything that failed.
/// </summary>
/// <remarks>
/// <para>
/// Presence comes first, because it is about the mutation rather than a value: a create missing a required
/// field is rejected here, before any write runs. Without it the omission reaches the database and the outcome
/// depends on the installation.
/// </para>
/// <para>
/// Then the two value tiers. The field verifier runs first (it is entity-specific and exactly typed) and then
/// the shared type processor. <b>Both run even when the first fails</b>, so violations from the two tiers
/// arrive together rather than the second being discovered only after the first is fixed. Nothing throws; the
/// unit of work decides what to do with a non-empty result.
/// </para>
/// </remarks>
public sealed class VerificationPipeline
{
    private readonly IReadOnlyDictionary<string, IEntityVerifiers> verifiers;
    private readonly IReadOnlyDictionary<string, string> fieldTypes;
    private readonly IProcessorRegistry processors;
    private readonly IReadOnlyDictionary<string, IReadOnlyList<string>> required;
    private readonly IReadOnlyDictionary<string, IReadOnlyList<string>> requiredEdges;
    private readonly IReadOnlyDictionary<string, IReadOnlyList<string>> nonNullable;

    /// <summary>A pipeline for the project's entities.</summary>
    /// <param name="verifiers">Keyed by entity name.</param>
    /// <param name="fieldTypes">"Entity.field" to declared type name.</param>
    /// <param name="processors">Where declared types' processors come from.</param>
    /// <param name="required">Entity to the fields that must be supplied on create.</param>
    /// <param name="requiredEdges">Entity to the to-one edges that must be attached on create.</param>
    /// <param name="nonNullable">Entity to the fields that cannot hold null.</param>
    public VerificationPipeline(
        IReadOnlyDictionary<string, IEntityVerifiers> verifiers,
        IReadOnlyDictionary<string, string> fieldTypes,
        IProcessorRegistry processors,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? required = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? requiredEdges = null,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? nonNullable = null)
    {
        this.verifiers = verifiers;
        this.fieldTypes = fieldTypes;
        this.processors = processors;
        this.required = required ?? new Dictionary<string, IReadOnlyList<string>>();
        this.requiredEdges = requiredEdges ?? new Dictionary<string, IReadOnlyList<string>>();
        this.nonNullable = nonNullable ?? new Dictionary<string, IReadOnlyList<string>>();
    }

    /// <summary>Verifies a mutation.</summary>
    /// <param name="mutation">The mutation.</param>
    /// <param name="cancellationToken">Cancels the verification.</param>
    /// <returns>Every violation, in the order found; empty when the mutation is fine.</returns>
    public async ValueTask<IReadOnlyList<FieldViolation>> VerifyAsync(
        Mutation.Mutation mutation,
        CancellationToken cancellationToken = default)
    {
        var violations = new List<FieldViolation>();
        violations.AddRange(VerifyPresence(mutation));
        violations.AddRange(VerifyEdgePresence(mutation));

        verifiers.TryGetValue(mutation.Entity, out var entityVerifiers);
        var nonNull = nonNullable.GetValueOrDefault(mutation.Entity) ?? [];
        var fields = mutation.Changes().Keys.Concat(entityVerifiers?.VerifiedFields() ?? []).Distinct();

        foreach (var field in fields)
        {
            var value = mutation.Pending(field);

            if (value is null && nonNull.Contains(field))
            {
                continue;
            }

            violations.AddRange(await VerifyFieldAsync(mutation, field, value, cancellationToken).ConfigureAwait(false));
        }

        return violations;
    }

    /// <summary>
    /// Required fields, on create only. <c>required</c> describes creating a row and nothing else: an update
    /// naming three fields is a partial update, and demanding the rest would make partial updates impossible.
    /// A key present but null counts as missing.
    /// </summary>
    private List<FieldViolation> VerifyPresence(Mutation.Mutation mutation)
    {
        var violations = new List<FieldViolation>();
        var changes = mutation.Changes();

        foreach (var field in nonNullable.GetValueOrDefault(mutation.Entity) ?? [])
        {
            if ((mutation.IsCreate || changes.ContainsKey(field)) && mutation.Pending(field) is null)
            {
                violations.Add(new FieldViolation(
                    mutation.Entity,
                    field,
                    new Violation("field.nonNullable", "This field cannot be null.")));
            }
        }

        if (!mutation.IsCreate)
        {
            return violations;
        }

        foreach (var field in required.GetValueOrDefault(mutation.Entity) ?? [])
        {
            if (changes.TryGetValue(field, out var supplied) && supplied is not null)
            {
                continue;
            }

            violations.Add(new FieldViolation(
                mutation.Entity,
                field,
                new Violation("field.required", "Required on create, and no value was supplied.")));
        }

        return violations;
    }

    /// <summary>
    /// Required edges, on create only: the same rule as presence, for a to-one edge instead of a field. On
    /// create there is no original to fall back to, so "this mutation never touched it" and "it is genuinely
    /// empty" are the same fact.
    /// </summary>
    private List<FieldViolation> VerifyEdgePresence(Mutation.Mutation mutation)
    {
        var violations = new List<FieldViolation>();

        if (!mutation.IsCreate)
        {
            return violations;
        }

        foreach (var edge in requiredEdges.GetValueOrDefault(mutation.Entity) ?? [])
        {
            if (mutation.PendingEdge(edge).Count > 0)
            {
                continue;
            }

            violations.Add(new FieldViolation(
                mutation.Entity,
                edge,
                new Violation("edge.required", "Required on create, and nothing was attached.")));
        }

        return violations;
    }

    private async ValueTask<List<FieldViolation>> VerifyFieldAsync(
        Mutation.Mutation mutation,
        string field,
        object? value,
        CancellationToken cancellationToken)
    {
        var result = await FieldTierAsync(mutation, field, value, cancellationToken).ConfigureAwait(false);

        if (value is not null && mutation.IsChanged(field))
        {
            result = result.Merge(TypeTier(mutation, field, value));
        }

        return [.. result.Violations.Select(violation => new FieldViolation(mutation.Entity, field, violation))];
    }

    private async ValueTask<Verification.Verification> FieldTierAsync(
        Mutation.Mutation mutation,
        string field,
        object? value,
        CancellationToken cancellationToken)
    {
        if (!verifiers.TryGetValue(mutation.Entity, out var entityVerifiers) || !entityVerifiers.VerifiedFields().Contains(field))
        {
            return Verification.Verification.Ok();
        }

        return await entityVerifiers.VerifyAsync(field, value, mutation, cancellationToken).ConfigureAwait(false);
    }

    private Verification.Verification TypeTier(Mutation.Mutation mutation, string field, object value)
    {
        if (!fieldTypes.TryGetValue($"{mutation.Entity}.{field}", out var type) || !processors.Has(type))
        {
            return Verification.Verification.Ok();
        }

        return processors.Write(type).Verify(value, mutation);
    }
}
