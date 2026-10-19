using Elephentity.Runtime.Catalogue;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;

namespace Elephentity.Runtime.Policy;

/// <summary>
/// Where read policies are enforced: a single object is permitted or refused, and a list is
/// filtered down to what the viewer may see.
/// </summary>
/// <remarks>
/// An entity with no read policies passes through untouched and its policies are never run. Rows a
/// list filter drops are logged at debug, with the policy and its reason, and never surfaced.
/// </remarks>
public sealed partial class ReadGate
{
    private readonly IEntityCatalogue catalogue;
    private readonly IViewerProvider viewers;
    private readonly ILogger log;

    /// <summary>A gate over <paramref name="catalogue" />, deciding for the viewer <paramref name="viewers" /> supplies.</summary>
    /// <param name="catalogue">Where the entities' policies come from.</param>
    /// <param name="viewers">Who is asking.</param>
    /// <param name="log">Where filtered rows are logged; nothing is logged when omitted.</param>
    public ReadGate(IEntityCatalogue catalogue, IViewerProvider viewers, ILogger? log = null)
    {
        this.catalogue = catalogue;
        this.viewers = viewers;
        this.log = log ?? NullLogger.Instance;
    }

    /// <summary>Whether <paramref name="entity" /> has read policies to enforce.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns><see langword="true" /> when reads of it are gated.</returns>
    public bool Guards(string entity) => !catalogue.ReadPolicies(entity).IsEmpty();

    /// <summary>Lets <paramref name="instance" /> through, or throws when the viewer may not read it.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="instance">The hydrated entity.</param>
    /// <param name="cancellationToken">Cancels the decision.</param>
    /// <returns>The same object.</returns>
    /// <exception cref="AccessDeniedException">A policy refused.</exception>
    public async ValueTask<object> PermitAsync(string entity, object instance, CancellationToken cancellationToken = default)
    {
        if (!Guards(entity))
        {
            return instance;
        }

        var decision = await catalogue.ReadPolicies(entity)
            .DecideAsync(instance, viewers.Viewer(), cancellationToken)
            .ConfigureAwait(false);

        if (decision.Outcome != PolicyOutcome.Allow)
        {
            throw new AccessDeniedException(entity, decision.Policy, decision.Reason);
        }

        return instance;
    }

    /// <summary>Drops the objects the viewer may not read, logging each at debug.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="instances">The hydrated entities.</param>
    /// <param name="cancellationToken">Cancels the filtering.</param>
    /// <returns>The ones the viewer may read, in their original order.</returns>
    public async ValueTask<IReadOnlyList<object>> RetainAsync(
        string entity,
        IReadOnlyList<object> instances,
        CancellationToken cancellationToken = default)
    {
        if (!Guards(entity))
        {
            return instances;
        }

        var viewer = viewers.Viewer();
        var retained = new List<object>(instances.Count);

        foreach (var instance in instances)
        {
            var decision = await catalogue.ReadPolicies(entity)
                .DecideAsync(instance, viewer, cancellationToken)
                .ConfigureAwait(false);

            if (decision.Outcome == PolicyOutcome.Allow)
            {
                retained.Add(instance);
                continue;
            }

            ReadPolicyFilteredAnEntity(log, entity, decision.Policy, decision.Reason);
        }

        return retained;
    }

    [LoggerMessage(
        EventId = 1,
        Level = LogLevel.Debug,
        Message = "Read policy filtered an entity. Entity={Entity} Policy={Policy} Reason={Reason}")]
    private static partial void ReadPolicyFilteredAnEntity(ILogger log, string entity, string? policy, string reason);
}
