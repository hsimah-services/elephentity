using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.SideEffect;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.Abstractions;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Runs an entity's side effects around a commit.
/// </summary>
/// <remarks>
/// A pre-commit handler that throws cancels the commit before persistence. A post-commit handler that throws is
/// logged and the remaining handlers still run, because the data is already committed and there is nothing to
/// cancel. Cancellation is the exception to that: a cancelled post-commit handler propagates.
/// </remarks>
public sealed partial class SideEffectDispatcher
{
    private readonly IReadOnlyDictionary<string, IEntitySideEffects> sideEffects;
    private readonly ILogger log;

    /// <summary>A dispatcher over the project's entities.</summary>
    /// <param name="sideEffects">Keyed by entity name.</param>
    /// <param name="log">Where post-commit failures are logged; nothing is logged when omitted.</param>
    public SideEffectDispatcher(IReadOnlyDictionary<string, IEntitySideEffects> sideEffects, ILogger? log = null)
    {
        this.sideEffects = sideEffects;
        this.log = log ?? NullLogger.Instance;
    }

    /// <summary>Runs the handlers for creating or updating each mutation.</summary>
    /// <param name="phase">When they run.</param>
    /// <param name="mutations">The mutations.</param>
    /// <param name="cancellationToken">Cancels the handlers.</param>
    /// <returns>A task that completes when every handler has run.</returns>
    public async Task DispatchAsync(
        SideEffectPhase phase,
        IReadOnlyList<Mutation.Mutation> mutations,
        CancellationToken cancellationToken = default)
    {
        foreach (var mutation in mutations)
        {
            await RunAsync(
                phase,
                mutation.IsCreate ? SideEffectEvent.Create : SideEffectEvent.Update,
                mutation,
                cancellationToken).ConfigureAwait(false);
        }
    }

    /// <summary>
    /// Runs the handlers for deleting each row. Explicit delete events, because a deletion context cannot
    /// distinguish deletion from an unchanged update.
    /// </summary>
    /// <param name="phase">When they run.</param>
    /// <param name="deleted">One context per planned removal, cascades included.</param>
    /// <param name="cancellationToken">Cancels the handlers.</param>
    /// <returns>A task that completes when every handler has run.</returns>
    public async Task DispatchDeletionsAsync(
        SideEffectPhase phase,
        IReadOnlyList<Mutation.Mutation> deleted,
        CancellationToken cancellationToken = default)
    {
        foreach (var context in deleted)
        {
            await RunAsync(phase, SideEffectEvent.Delete, context, cancellationToken).ConfigureAwait(false);
        }
    }

    private async Task RunAsync(
        SideEffectPhase phase,
        SideEffectEvent sideEffectEvent,
        Mutation.Mutation context,
        CancellationToken cancellationToken)
    {
        if (!sideEffects.TryGetValue(context.Entity, out var entitySideEffects))
        {
            return;
        }

        foreach (var handler in entitySideEffects.Handlers(phase, sideEffectEvent, context))
        {
            if (phase == SideEffectPhase.PreCommit)
            {
                await handler(cancellationToken).ConfigureAwait(false);
                continue;
            }

            try
            {
                await handler(cancellationToken).ConfigureAwait(false);
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                PostCommitSideEffectFailed(log, exception, context.Entity, sideEffectEvent.ToWire());
            }
        }
    }

    [LoggerMessage(
        EventId = 2,
        Level = LogLevel.Error,
        Message = "A post-commit side effect failed after the data was committed. Entity={Entity} Event={Event}")]
    private static partial void PostCommitSideEffectFailed(ILogger log, Exception exception, string entity, string @event);
}
