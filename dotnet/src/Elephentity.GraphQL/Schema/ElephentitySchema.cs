using Elephentity.GraphQL.Manifest;
using GraphQL.Types;

namespace Elephentity.GraphQL.Schema;

/// <summary>
/// Builds the GraphQL.NET schema for a manifest.
/// </summary>
public static class ElephentitySchema
{
    /// <summary>The schema for <paramref name="manifest" />, the same one the PHP package builds from the same spec.</summary>
    /// <param name="manifest">The compiled GraphQL manifest.</param>
    /// <param name="options">Page sizes; the defaults when omitted.</param>
    /// <returns>
    /// The schema, with <c>Node</c>, <c>PageInfo</c> and <c>node(id: ID!)</c> registered, the roots named <c>RootQuery</c> and
    /// <c>RootMutation</c>, and no mutation root when nothing mutates. Resolve <c>IEntityGateway</c> (and, for declared value types,
    /// <c>IProcessorRegistry</c>) from the request services of each execution.
    /// </returns>
    public static ISchema Build(GraphQLManifest manifest, ElephentityGraphQLOptions? options = null)
    {
        ArgumentNullException.ThrowIfNull(manifest);

        options ??= new ElephentityGraphQLOptions();
        options.Validate();

        return new SchemaAssembler(manifest, options).Build();
    }
}
