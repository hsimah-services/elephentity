using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Type;
using Elephentity.Runtime.UnitOfWork;
using Elephentity.Runtime.Verification;
using Microsoft.Extensions.Logging;

namespace Elephentity.Runtime.Gateway;

/// <summary>
/// Builds a unit of work per commit.
/// </summary>
/// <remarks>
/// One per commit rather than one shared: a unit of work accumulates state and is emptied by committing, so
/// reusing one across requests would mean deciding what happens to a half-built commit that was never finished.
/// Assembling it means reading the catalogue for verifiers, side effects and deletion rules, all generated and
/// all derived from the spec.
/// </remarks>
public sealed class UnitOfWorkFactory : IDeletionRules
{
    private readonly IStorageAdaptor storage;
    private readonly IEntityCatalogue catalogue;
    private readonly IProcessorRegistry processors;
    private readonly ILogger? log;

    /// <summary>A factory over the project's catalogue.</summary>
    /// <param name="storage">Where commits write.</param>
    /// <param name="catalogue">Everything generated from the spec.</param>
    /// <param name="processors">Where declared types' processors come from.</param>
    /// <param name="log">Where post-commit failures are logged.</param>
    public UnitOfWorkFactory(
        IStorageAdaptor storage,
        IEntityCatalogue catalogue,
        IProcessorRegistry processors,
        ILogger? log = null)
    {
        this.storage = storage;
        this.catalogue = catalogue;
        this.processors = processors;
        this.log = log;
    }

    /// <summary>A fresh, empty unit of work.</summary>
    /// <returns>The unit of work.</returns>
    public UnitOfWork.UnitOfWork Create()
    {
        var verifiers = new Dictionary<string, IEntityVerifiers>();
        var sideEffects = new Dictionary<string, IEntitySideEffects>();
        var required = new Dictionary<string, IReadOnlyList<string>>();
        var requiredEdges = new Dictionary<string, IReadOnlyList<string>>();
        var nonNullable = new Dictionary<string, IReadOnlyList<string>>();
        var unique = new Dictionary<string, IReadOnlyList<string>>();

        foreach (var entity in catalogue.Entities())
        {
            verifiers[entity] = catalogue.Verifiers(entity);
            sideEffects[entity] = catalogue.SideEffects(entity);
            required[entity] = catalogue.RequiredFields(entity);
            nonNullable[entity] = catalogue.NonNullableFields(entity);
            requiredEdges[entity] = catalogue.RequiredEdges(entity);
            unique[entity] = catalogue.UniqueFields(entity);
        }

        var fieldTypes = catalogue.FieldTypes();
        var encoder = new ValueEncoder(fieldTypes, processors);

        return new UnitOfWork.UnitOfWork(
            storage,
            new VerificationPipeline(verifiers, fieldTypes, processors, required, requiredEdges, nonNullable),
            encoder,
            new SideEffectDispatcher(sideEffects, log),
            managed: new ManagedFields(catalogue.ManagedFields()),
            planner: new DeletionPlanner(storage, this),
            unique: new UniquenessCheck(storage, encoder, unique));
    }

    /// <inheritdoc />
    public IReadOnlyList<DeletionRule> RulesFor(string entity) => catalogue.DeletionRules(entity);
}
