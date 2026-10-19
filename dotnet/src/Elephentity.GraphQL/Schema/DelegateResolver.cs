using GraphQL;
using GraphQL.Resolvers;

namespace Elephentity.GraphQL.Schema;

/// <summary>A field resolver from a delegate, so every resolver in the package has the one shape and none is ambiguous.</summary>
internal sealed class DelegateResolver(Func<IResolveFieldContext, ValueTask<object?>> resolve) : IFieldResolver
{
    public DelegateResolver(Func<IResolveFieldContext, object?> resolve)
        : this(context => ValueTask.FromResult(resolve(context)))
    {
    }

    public ValueTask<object?> ResolveAsync(IResolveFieldContext context) => resolve(context);
}
