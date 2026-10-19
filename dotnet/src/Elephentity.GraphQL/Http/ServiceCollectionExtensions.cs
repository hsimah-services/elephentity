using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Schema;
using GraphQL.Types;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;

namespace Elephentity.GraphQL.Http;

/// <summary>
/// Registers the GraphQL schema and its executor.
/// </summary>
public static class ServiceCollectionExtensions
{
    /// <summary>Adds the schema for <paramref name="manifest" /> and the executor that runs it.</summary>
    /// <param name="services">The services.</param>
    /// <param name="manifest">The generated GraphQL manifest.</param>
    /// <param name="configure">Page sizes, limits and introspection.</param>
    /// <returns><paramref name="services" />.</returns>
    /// <remarks>
    /// The schema is built once, at the first request for it. It needs an <c>IEntityGateway</c> in the container (the generated
    /// <c>Add&lt;Project&gt;Entities</c> registers one) and, for declared value types, an <c>IProcessorRegistry</c>. The viewer is the
    /// runtime's: register an <c>IViewerProvider</c> that reads the current request and every read and write is gated by it.
    /// </remarks>
    public static IServiceCollection AddElephentityGraphQL(
        this IServiceCollection services,
        GraphQLManifest manifest,
        Action<ElephentityGraphQLOptions>? configure = null)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(manifest);

        var options = new ElephentityGraphQLOptions();

        configure?.Invoke(options);
        options.Validate();

        services.TryAddSingleton(options);
        services.TryAddSingleton<ISchema>(_ => ElephentitySchema.Build(manifest, options));
        services.TryAddSingleton<ElephentityGraphQLExecutor>();

        return services;
    }
}
