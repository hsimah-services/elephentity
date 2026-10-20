using System.Text;
using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Verification;
using GraphQL;
using GraphQL.Execution;
using GraphQL.SystemTextJson;
using GraphQL.Transport;
using GraphQL.Types;
using GraphQL.Validation;
using GraphQL.Validation.Complexity;
using GraphQL.Validation.Rules.Custom;
using Microsoft.Extensions.Logging;

namespace Elephentity.GraphQL.Http;

/// <summary>
/// Runs a GraphQL request against the schema, and answers in JSON.
/// </summary>
/// <remarks>
/// The one place a request is executed, so the HTTP endpoint and a test do exactly the same thing: depth and complexity limits,
/// introspection on or off, the error mapping of <see cref="ElephentityErrorInfoProvider" />, and the unmapped exceptions logged
/// where the client cannot see them.
/// </remarks>
public sealed class ElephentityGraphQLExecutor
{
    private readonly ISchema schema;
    private readonly ElephentityGraphQLOptions options;
    private readonly ILogger? log;
    private readonly DocumentExecuter executer = new();

    /// <summary>Creates an executor.</summary>
    /// <param name="schema">The schema from <see cref="Schema.ElephentitySchema.Build" />.</param>
    /// <param name="options">The limits.</param>
    /// <param name="log">Where unmapped exceptions are logged.</param>
    public ElephentityGraphQLExecutor(ISchema schema, ElephentityGraphQLOptions options, ILogger<ElephentityGraphQLExecutor>? log = null)
    {
        ArgumentNullException.ThrowIfNull(schema);
        ArgumentNullException.ThrowIfNull(options);

        this.schema = schema;
        this.options = options;
        this.log = log;
        Serializer = new GraphQLSerializer(new ElephentityErrorInfoProvider());
    }

    /// <summary>The serializer for requests and results.</summary>
    public GraphQLSerializer Serializer { get; }

    /// <summary>Executes a request.</summary>
    /// <param name="request">The query, operation name and variables.</param>
    /// <param name="services">The request's services: where <c>IEntityGateway</c> is resolved from.</param>
    /// <param name="cancellationToken">Cancels the execution.</param>
    /// <returns>The result, ready to serialize with <see cref="Serializer" />.</returns>
    public Task<ExecutionResult> ExecuteAsync(GraphQLRequest request, IServiceProvider services, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        ArgumentNullException.ThrowIfNull(services);

        return executer.ExecuteAsync(execution =>
        {
            execution.Schema = schema;
            execution.Query = request.Query;
            execution.OperationName = request.OperationName;
            execution.Variables = request.Variables;
            execution.Extensions = request.Extensions;
            execution.RequestServices = services;
            execution.CancellationToken = cancellationToken;
            execution.ThrowOnUnhandledException = false;
            execution.UnhandledExceptionDelegate = Unhandled;

            var rules = DocumentValidator.CoreRules.AsEnumerable();

            if (options.MaxDepth is not null || options.MaxComplexity is not null)
            {
                rules = rules.Append(new ComplexityValidationRule(new ComplexityOptions
                {
                    MaxDepth = options.MaxDepth,
                    MaxComplexity = options.MaxComplexity,
                }));
            }

            if (!options.EnableIntrospection)
            {
                rules = rules.Append(NoIntrospectionValidationRule.Instance);
            }

            execution.ValidationRules = rules.ToList();
        });
    }

    /// <summary>Executes a request and serializes the result.</summary>
    /// <param name="request">The request.</param>
    /// <param name="services">The request's services.</param>
    /// <param name="cancellationToken">Cancels the execution.</param>
    /// <returns>The JSON response body.</returns>
    public async Task<string> ExecuteToJsonAsync(GraphQLRequest request, IServiceProvider services, CancellationToken cancellationToken = default) =>
        Serializer.Serialize(await ExecuteAsync(request, services, cancellationToken).ConfigureAwait(false));

    private static readonly Action<ILogger, string?, Exception> Unexpected =
        LoggerMessage.Define<string?>(LogLevel.Error, new EventId(1, "UnhandledGraphQLException"), "Unhandled exception resolving {Field}.");

    private Task Unhandled(UnhandledExceptionContext context)
    {
        // What the client is told is decided by the error info provider; this is only about what the operator is told. A refusal
        // or a bad request is the system working, so it is not an error in the log.
        if (context.OriginalException is not (AccessDeniedException or CommitRejectedException or EntityNotFoundException or GraphQLInputException or OperationCanceledException))
        {
            if (log is not null)
            {
                Unexpected(log, context.FieldContext?.FieldDefinition?.Name, context.OriginalException);
            }
        }

        return Task.CompletedTask;
    }
}
