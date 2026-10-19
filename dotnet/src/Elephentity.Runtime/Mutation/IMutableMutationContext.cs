namespace Elephentity.Runtime.Mutation;

/// <summary>Pending state that actions and pre-commit side effects may change.</summary>
public interface IMutableMutationContext : IMutationContext, IMutationBuffer
{
}
