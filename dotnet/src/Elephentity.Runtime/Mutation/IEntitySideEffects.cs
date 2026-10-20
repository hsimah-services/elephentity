using Elephentity.Runtime.SideEffect;

namespace Elephentity.Runtime.Mutation;

/// <summary>An entity's side effects: the handlers to run for an event, in a phase.</summary>
public interface IEntitySideEffects
{
    /// <summary>The handlers for <paramref name="sideEffectEvent" /> in <paramref name="phase" />, in declaration order.</summary>
    /// <remarks>
    /// Each handler is asynchronous because a side effect can reach storage or another service. Only
    /// pre-commit handlers may change <paramref name="context" />; see <see cref="SideEffectPhaseExtensions.AllowsMutation" />.
    /// </remarks>
    /// <param name="phase">When the handlers run.</param>
    /// <param name="sideEffectEvent">What happened.</param>
    /// <param name="context">The mutation the handlers react to.</param>
    /// <returns>The handlers.</returns>
    IEnumerable<Func<CancellationToken, ValueTask>> Handlers(
        SideEffectPhase phase,
        SideEffectEvent sideEffectEvent,
        IMutableMutationContext context);
}
