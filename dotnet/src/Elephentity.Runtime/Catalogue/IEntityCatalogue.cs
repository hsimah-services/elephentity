using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.Catalogue;

/// <summary>
/// Everything the runtime needs to know about entities, addressed by name.
/// </summary>
/// <remarks>
/// <para>
/// Generated, because all of it is a walk over the spec. The runtime has no schema and should not gain one: the
/// IR is a build-time artefact and loading it per request would be paying for compilation twice. Implementations
/// resolve generated classes through the application's service provider rather than taking dozens of constructor
/// arguments, so adding an entity does not widen a signature.
/// </para>
/// <para>
/// Where the PHP catalogue hands back an object and the runtime calls a method on it by name (a finder, a
/// mutator, a getter), this one does the call itself: <see cref="RunQueryAsync" />, <see cref="RunActionAsync" />
/// and <see cref="ValuesOf" />. Dispatching by name needs reflection, which generated code and Native AOT
/// forbid; generated code knows the names at build time and switches on them.
/// </para>
/// </remarks>
public interface IEntityCatalogue
{
    /// <summary>Every entity's name.</summary>
    /// <returns>The names.</returns>
    IReadOnlyList<string> Entities();

    /// <summary>The hydrator for <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The hydrator.</returns>
    IHydrator<object> Hydrator(string entity);

    /// <summary>The field verifiers of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The verifiers; one that verifies nothing when there are none.</returns>
    IEntityVerifiers Verifiers(string entity);

    /// <summary>The side effects of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The side effects.</returns>
    IEntitySideEffects SideEffects(string entity);

    /// <summary>The read policies of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The policies; <see cref="NoPolicies" /> when it has none.</returns>
    IEntityReadPolicies ReadPolicies(string entity);

    /// <summary>The write policies of <paramref name="entity" />.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The policies; <see cref="NoPolicies" /> when it has none.</returns>
    IEntityWritePolicies WritePolicies(string entity);

    /// <summary>What depends on <paramref name="entity" /> when a row of it is deleted.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The deletion rules.</returns>
    IReadOnlyList<DeletionRule> DeletionRules(string entity);

    /// <summary>Where each edge points.</summary>
    /// <returns>"Entity.edge" to the target entity.</returns>
    IReadOnlyDictionary<string, string> EdgeTargets();

    /// <summary>Which fields carry a declared type.</summary>
    /// <returns>"Entity.field" to the declared type's name.</returns>
    IReadOnlyDictionary<string, string> FieldTypes();

    /// <summary>The names of an entity's fields.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The field names.</returns>
    IReadOnlyList<string> FieldNames(string entity);

    /// <summary>
    /// Fields that must be supplied when the row is created. Exposed because nothing downstream could work it
    /// out: the runtime has no schema, so without this <c>required: true</c> is a word in the spec that changes
    /// nothing. A field with a default is not listed; generated input seeds it on create.
    /// </summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The field names.</returns>
    IReadOnlyList<string> RequiredFields(string entity);

    /// <summary>Fields that must never receive null, on create or update.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The field names.</returns>
    IReadOnlyList<string> NonNullableFields(string entity);

    /// <summary>
    /// To-one edges that must be attached when the row is created. Checked at commit, never as a NOT NULL
    /// column: a link is always written after the row it links, so the column has to stay nullable regardless.
    /// </summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The edge names.</returns>
    IReadOnlyList<string> RequiredEdges(string entity);

    /// <summary>
    /// Fields carrying a uniqueness constraint, so the commit can check one before the database does and report
    /// it as a violation rather than a storage exception.
    /// </summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The field names.</returns>
    IReadOnlyList<string> UniqueFields(string entity);

    /// <summary>Fields the framework fills, and when.</summary>
    /// <returns>"Entity.field" to the policy.</returns>
    IReadOnlyDictionary<string, Managed> ManagedFields();

    /// <summary>A declared query's parameters, in order.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="query">The query.</param>
    /// <returns>The parameter names.</returns>
    IReadOnlyList<string> QueryArguments(string entity, string query);

    /// <summary>A declared action's arguments, in declaration order.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="action">The action.</param>
    /// <returns>The argument names.</returns>
    IReadOnlyList<string> ActionArguments(string entity, string action);

    /// <summary>Decode raw action arguments into the declared domain values.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="action">The action.</param>
    /// <param name="args">The raw arguments.</param>
    /// <returns>The decoded arguments.</returns>
    IReadOnlyDictionary<string, object?> DecodeActionArguments(
        string entity,
        string action,
        IReadOnlyDictionary<string, object?> args);

    /// <summary>
    /// Apply raw input to a mutation. The one thing a name-addressed layer cannot do for itself: a GraphQL
    /// argument arrives as a string and a setter wants a moment. Generated code knows both ends, so it does the
    /// conversion and the gateway stays untyped only at its edge.
    /// </summary>
    /// <param name="entity">The entity.</param>
    /// <param name="buffer">The mutation to write into.</param>
    /// <param name="input">Field name to raw value.</param>
    void Apply(string entity, IMutationBuffer buffer, IReadOnlyDictionary<string, object?> input);

    /// <summary>Run a query the spec declared, by name.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="query">The query.</param>
    /// <param name="args">Argument name to value; a missing one is null.</param>
    /// <param name="cancellationToken">Cancels the query.</param>
    /// <returns>An <see cref="IEntityQuery{T}" /> for a collection, or the entity or null for a singular query.</returns>
    /// <exception cref="InvalidOperationException">The entity declares no such query.</exception>
    ValueTask<object?> RunQueryAsync(
        string entity,
        string query,
        IReadOnlyDictionary<string, object?> args,
        CancellationToken cancellationToken = default);

    /// <summary>Run a declared action against a mutation, by name.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="mutation">The mutation the action records its changes in.</param>
    /// <param name="action">The call, with decoded arguments.</param>
    /// <param name="cancellationToken">Cancels the action.</param>
    /// <returns>A task that completes when the action has recorded its changes.</returns>
    /// <exception cref="InvalidOperationException">The entity declares no such action.</exception>
    ValueTask RunActionAsync(
        string entity,
        IMutableMutationContext mutation,
        ActionCall action,
        CancellationToken cancellationToken = default);

    /// <summary>The row as it stands, in domain form, so a verifier can compare against what was there.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="instance">The hydrated entity.</param>
    /// <returns>Field name to value.</returns>
    IReadOnlyDictionary<string, object?> ValuesOf(string entity, object instance);

    /// <summary>Every interface the application must implement before it can start.</summary>
    /// <returns>The interface types.</returns>
    IReadOnlyList<System.Type> Contracts();
}
