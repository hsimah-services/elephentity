using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.Testing;

/// <summary>
/// An <see cref="IEntityCatalogue" /> that knows nothing, to override only what a test cares about.
/// </summary>
/// <remarks>
/// The real catalogue is generated and covers every entity in a spec, so a test of one behaviour (a gate, the
/// unit of work, a contract check) should not need one. Every collection is empty, every policy set is
/// <see cref="NoPolicies" />, and anything that would have to run generated code throws
/// <see cref="NotSupportedException" /> until a test overrides it.
/// </remarks>
public class StubEntityCatalogue : IEntityCatalogue
{
    /// <inheritdoc />
    public virtual IReadOnlyList<string> Entities() => [];

    /// <inheritdoc />
    public virtual IHydrator<object> Hydrator(string entity) => throw Unsupported(nameof(Hydrator), entity);

    /// <inheritdoc />
    public virtual IEntityVerifiers Verifiers(string entity) => new NoVerifiers();

    /// <inheritdoc />
    public virtual IEntitySideEffects SideEffects(string entity) => new NoSideEffects();

    /// <inheritdoc />
    public virtual IEntityReadPolicies ReadPolicies(string entity) => new NoPolicies();

    /// <inheritdoc />
    public virtual IEntityWritePolicies WritePolicies(string entity) => new NoPolicies();

    /// <inheritdoc />
    public virtual IReadOnlyList<DeletionRule> DeletionRules(string entity) => [];

    /// <inheritdoc />
    public virtual IReadOnlyDictionary<string, string> EdgeTargets() => new Dictionary<string, string>();

    /// <inheritdoc />
    public virtual IReadOnlyDictionary<string, string> FieldTypes() => new Dictionary<string, string>();

    /// <inheritdoc />
    public virtual IReadOnlyList<string> FieldNames(string entity) => [];

    /// <inheritdoc />
    public virtual IReadOnlyList<string> RequiredFields(string entity) => [];

    /// <inheritdoc />
    public virtual IReadOnlyList<string> NonNullableFields(string entity) => [];

    /// <inheritdoc />
    public virtual IReadOnlyList<string> RequiredEdges(string entity) => [];

    /// <inheritdoc />
    public virtual IReadOnlyList<string> UniqueFields(string entity) => [];

    /// <inheritdoc />
    public virtual IReadOnlyDictionary<string, Managed> ManagedFields() => new Dictionary<string, Managed>();

    /// <inheritdoc />
    public virtual IReadOnlyList<string> QueryArguments(string entity, string query) => [];

    /// <inheritdoc />
    public virtual IReadOnlyList<string> ActionArguments(string entity, string action) => [];

    /// <inheritdoc />
    public virtual IReadOnlyDictionary<string, object?> DecodeActionArguments(
        string entity,
        string action,
        IReadOnlyDictionary<string, object?> args) => args;

    /// <inheritdoc />
    public virtual void Apply(string entity, IMutationBuffer buffer, IReadOnlyDictionary<string, object?> input)
    {
        ArgumentNullException.ThrowIfNull(buffer);
        ArgumentNullException.ThrowIfNull(input);

        foreach (var (field, value) in input)
        {
            buffer.Assign(field, value);
        }
    }

    /// <inheritdoc />
    public virtual ValueTask<object?> RunQueryAsync(
        string entity,
        string query,
        IReadOnlyDictionary<string, object?> args,
        CancellationToken cancellationToken = default) => throw Unsupported(nameof(RunQueryAsync), $"{entity}.{query}");

    /// <inheritdoc />
    public virtual ValueTask RunActionAsync(
        string entity,
        IMutableMutationContext mutation,
        ActionCall action,
        CancellationToken cancellationToken = default) => throw Unsupported(nameof(RunActionAsync), $"{entity}.{action?.Name}");

    /// <inheritdoc />
    public virtual IReadOnlyDictionary<string, object?> ValuesOf(string entity, object instance) =>
        new Dictionary<string, object?>();

    /// <inheritdoc />
    public virtual IReadOnlyList<System.Type> Contracts() => [];

    private static NotSupportedException Unsupported(string member, string subject) =>
        new($"StubEntityCatalogue.{member}({subject}) was not overridden by this test.");

    private sealed class NoVerifiers : IEntityVerifiers
    {
        public ValueTask<Verification.Verification> VerifyAsync(
            string field,
            object? value,
            IMutationContext context,
            CancellationToken cancellationToken = default) => ValueTask.FromResult(Verification.Verification.Ok());

        public IReadOnlyList<string> VerifiedFields() => [];
    }

    private sealed class NoSideEffects : IEntitySideEffects
    {
        public IEnumerable<Func<CancellationToken, ValueTask>> Handlers(
            SideEffect.SideEffectPhase phase,
            SideEffect.SideEffectEvent sideEffectEvent,
            IMutableMutationContext context) => [];
    }
}
