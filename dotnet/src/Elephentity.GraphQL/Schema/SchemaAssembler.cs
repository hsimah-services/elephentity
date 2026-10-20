using System.Globalization;
using System.Text.Json;
using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Relay;
using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Type;
using GraphQL;
using GraphQL.Resolvers;
using GraphQL.Types;
using Microsoft.Extensions.DependencyInjection;

namespace Elephentity.GraphQL.Schema;

/// <summary>
/// Turns a manifest into a schema. The ports of <c>SchemaBuilder</c>, <c>TypeRegistrar</c> and <c>MutationRegistrar</c>, in one place
/// because GraphQL.NET has no registration hooks to separate them across: every decision was made when the manifest was compiled, so
/// what is left is loops.
/// </summary>
internal sealed partial class SchemaAssembler(GraphQLManifest manifest, ElephentityGraphQLOptions options)
{
    private readonly Dictionary<string, IGraphType> types = new(StringComparer.Ordinal);
    private readonly Dictionary<string, string> connectionTargets = new(StringComparer.Ordinal);
    private readonly ObjectGraphType query = new() { Name = "RootQuery" };
    private readonly ObjectGraphType mutation = new() { Name = "RootMutation" };

    public ISchema Build()
    {
        var node = Node();
        var pageInfo = PageInfo();

        Add(node);
        Add(pageInfo);

        foreach (var enumeration in manifest.Enums.Values)
        {
            Add(Enum(enumeration));
        }

        var objects = new Dictionary<string, ObjectGraphType>(StringComparer.Ordinal);

        foreach (var entry in manifest.Objects.Values)
        {
            var type = Object(entry, node);

            objects[entry.Name] = type;
            Add(type);
        }

        foreach (var entry in manifest.Objects.Values)
        {
            foreach (var connection in entry.Connections.Values)
            {
                Connection(objects[entry.Name], entry.Name, connection.ToType, connection.Name, null, resolve: context =>
                    ValueTask.FromResult<object?>(connection.Query(context.Source!)));
            }
        }

        foreach (var root in manifest.Roots.Values)
        {
            RootFields(root);
        }

        foreach (var published in manifest.Queries.Values)
        {
            Published(published);
        }

        DeleteTypes();

        foreach (var entry in manifest.Mutations.Values)
        {
            Mutation(entry);
        }

        query.AddField(new FieldType
        {
            Name = "node",
            ResolvedType = new GraphQLTypeReference("Node"),
            Arguments = new QueryArguments(Argument("id", new NonNullGraphType(new GraphQLTypeReference("ID")))),
            Resolver = new DelegateResolver(NodeAsync),
        });

        var schema = new global::GraphQL.Types.Schema(new NoServices())
        {
            Query = query,
            Mutation = mutation.Fields.Count == 0 ? null : mutation,
        };

        foreach (var type in types.Values)
        {
            schema.RegisterType(type);
        }

        return schema;
    }

    // The schema is made of instances, so there is nothing for a service provider to build; the empty one keeps the schema off the
    // reflection-based constructor.
    private sealed class NoServices : IServiceProvider
    {
        public object? GetService(System.Type serviceType) => null;
    }

    private static QueryArgument Argument(string name, IGraphType type) => new(type) { Name = name };

    private static GraphQLTypeReference Ref(string name) => new GraphQLTypeReference(name);

    private static FieldType Field(string name, IGraphType type, string? description = null, IFieldResolver? resolver = null) =>
        new() { Name = name, ResolvedType = type, Description = description, Resolver = resolver };

    private static string LowerFirst(string name) => name.Length == 0 ? name : char.ToLowerInvariant(name[0]) + name[1..];

    private static string UpperFirst(string name) => name.Length == 0 ? name : char.ToUpperInvariant(name[0]) + name[1..];

    private void Add(IGraphType type)
    {
        if (!types.TryAdd(type.Name, type))
        {
            throw new InvalidOperationException(
                $"Two GraphQL types are named {type.Name}. The manifest (or a connection or mutation derived from it) defines it twice.");
        }
    }

    private InterfaceGraphType Node()
    {
        var node = new InterfaceGraphType { Name = "Node" };

        node.AddField(Field("id", new NonNullGraphType(Ref("ID"))));
        node.ResolveType = value => manifest.Objects.Values
            .Where(entry => entry.Interfaces.Contains("Node") && entry.IsInstance(value))
            .Select(entry => types.TryGetValue(entry.Name, out var type) ? type as IObjectGraphType : null)
            .FirstOrDefault();

        return node;
    }

    private static ObjectGraphType PageInfo()
    {
        var info = new ObjectGraphType { Name = "PageInfo" };

        info.AddField(Field("hasNextPage", new NonNullGraphType(Ref("Boolean")), resolver: new DelegateResolver(c => ((PageInfoResult)c.Source!).HasNextPage)));
        info.AddField(Field("hasPreviousPage", new NonNullGraphType(Ref("Boolean")), resolver: new DelegateResolver(c => ((PageInfoResult)c.Source!).HasPreviousPage)));
        info.AddField(Field("startCursor", Ref("String"), resolver: new DelegateResolver(c => ((PageInfoResult)c.Source!).StartCursor)));
        info.AddField(Field("endCursor", Ref("String"), resolver: new DelegateResolver(c => ((PageInfoResult)c.Source!).EndCursor)));

        return info;
    }

    private static EnumerationGraphType Enum(EnumTypeEntry entry)
    {
        var enumeration = new EnumerationGraphType { Name = entry.Name };

        foreach (var (name, stored) in entry.Values)
        {
            enumeration.Add(name, stored);
        }

        return enumeration;
    }

    private static ObjectGraphType Object(ObjectTypeEntry entry, InterfaceGraphType node)
    {
        var type = new ObjectGraphType
        {
            Name = entry.Name,
            // The PHP registrar writes an empty description rather than none, and the schema is meant to be indistinguishable.
            Description = entry.Description ?? string.Empty,
            IsTypeOf = entry.IsInstance,
        };

        foreach (var field in entry.Fields.Values)
        {
            if ((field.Read is null) == (field.ReadAsync is null))
            {
                throw new InvalidOperationException($"{entry.Name}.{field.Name} must give exactly one of Read and ReadAsync.");
            }

            type.AddField(Field(field.Name, field.Type.ToGraphType(), field.Description ?? string.Empty, new DelegateResolver(context => Read(field, entry.Name, context))));
        }

        // The interface checks the fields it requires, so it can only be added once they are there.
        foreach (var name in entry.Interfaces)
        {
            type.AddResolvedInterface(name == "Node" ? node : throw new InvalidOperationException($"{entry.Name} implements {name}, and only Node exists."));
        }

        return type;
    }

    private static async ValueTask<object?> Read(FieldEntry field, string typeName, IResolveFieldContext context)
    {
        var value = field switch
        {
            { ReadAsync: { } readAsync } => await readAsync(context.Source!, context.CancellationToken).ConfigureAwait(false),
            { Read: { } read } => read(context.Source!),
            _ => throw new InvalidOperationException($"{typeName}.{field.Name} has neither Read nor ReadAsync."),
        };

        return value is null ? null : Encode(value, field, typeName, context);
    }

    private static object? Encode(object value, FieldEntry field, string typeName, IResolveFieldContext context) => field.Encoding switch
    {
        FieldEncoding.Value => value,
        FieldEncoding.GlobalId => GlobalId.Encode(typeName, Scalar(value)),
        FieldEncoding.Id => Scalar(value),
        FieldEncoding.Datetime => value is DateTimeOffset moment ? moment.ToString("yyyy-MM-dd'T'HH:mm:sszzz", CultureInfo.InvariantCulture) : value,
        FieldEncoding.Json => value is JsonElement json ? json.GetRawText() : value,
        FieldEncoding.Processor => Unwind(value, field, context),
        _ => throw new InvalidOperationException($"Unknown field encoding {field.Encoding}."),
    };

    private static string Scalar(object value) => value switch
    {
        EntityId id => id.ToString(),
        string text => text,
        long or int => Convert.ToString(value, CultureInfo.InvariantCulture)!,
        _ => throw new InvalidOperationException($"An id resolved to {value.GetType().Name}, which cannot be a global identifier."),
    };

    private static object Unwind(object value, FieldEntry field, IResolveFieldContext context)
    {
        var processors = context.RequestServices?.GetService<IProcessorRegistry>();

        if (field.ValueType is null || processors is null || !processors.Has(field.ValueType))
        {
            throw new InvalidOperationException(
                $"Field \"{field.Name}\" holds the declared type {field.ValueType ?? "a value type"}, which travels as its backing primitive. Register an IProcessorRegistry so it can reach that type's write processor.");
        }

        return processors.Write(field.ValueType).Write(value);
    }

    private static IEntityGateway Gateway(IResolveFieldContext context) =>
        context.RequestServices?.GetRequiredService<IEntityGateway>()
        ?? throw new InvalidOperationException("The execution has no request services, so there is no IEntityGateway to resolve against.");

    private void AddConnectionTypes(string name, string to, IReadOnlyDictionary<string, GraphQLType>? whereArgs)
    {
        var edge = new ObjectGraphType { Name = name + "Edge" };

        edge.AddField(Field("cursor", Ref("String"), resolver: new DelegateResolver(c => ((EdgeResult)c.Source!).Cursor)));
        edge.AddField(Field("node", Ref(to), resolver: new DelegateResolver(c => ((EdgeResult)c.Source!).Node)));

        var connection = new ObjectGraphType { Name = name };

        connection.AddField(Field("nodes", new ListGraphType(Ref(to)), resolver: new DelegateResolver(c => ((ConnectionResult)c.Source!).Nodes)));
        connection.AddField(Field("edges", new ListGraphType(Ref(edge.Name)), resolver: new DelegateResolver(c => ((ConnectionResult)c.Source!).Edges)));
        connection.AddField(Field("pageInfo", new NonNullGraphType(Ref("PageInfo")), resolver: new DelegateResolver(c => ((ConnectionResult)c.Source!).PageInfo)));
        connection.AddField(Field(
            "totalCount",
            Ref("Int"),
            "How many match, ignoring pagination.",
            new DelegateResolver(async c => (object?)await ((ConnectionResult)c.Source!).TotalAsync().ConfigureAwait(false))));

        Add(edge);
        Add(connection);

        if (whereArgs is not null)
        {
            var where = new InputObjectGraphType { Name = name + "WhereArgs" };

            foreach (var (argument, type) in whereArgs)
            {
                where.AddField(Field(argument, type.ToGraphType()));
            }

            Add(where);
        }
    }

    /// <summary>A connection field on <paramref name="from" />, its types, and its arguments.</summary>
    private void Connection(
        ObjectGraphType from,
        string fromName,
        string to,
        string fieldName,
        IReadOnlyDictionary<string, GraphQLType>? whereArgs,
        Func<IResolveFieldContext, ValueTask<object?>> resolve)
    {
        // A published query is named by its field, so two of them returning one type do not share a connection; one that takes no arguments
        // has nothing to put in a `where`, and an input object with no fields is not valid GraphQL, so it gets no `where` at all.
        var name = $"{fromName}To{(whereArgs is null ? to : UpperFirst(fieldName))}Connection";

        if (whereArgs is { Count: 0 })
        {
            whereArgs = null;
            name = $"{fromName}To{UpperFirst(fieldName)}Connection";
        }

        // Two edges from one type to another (a Post's `related` and its inverse `relatedFrom`) are two fields of one connection
        // type, as in PHP, where registering the type again was a harmless overwrite. Two targets under one name is a defect.
        if (connectionTargets.TryGetValue(name, out var previous))
        {
            if (previous != to)
            {
                throw new InvalidOperationException($"GraphQL connection \"{name}\" targets both \"{previous}\" and \"{to}\".");
            }
        }
        else
        {
            connectionTargets[name] = to;
            AddConnectionTypes(name, to, whereArgs);
        }

        var arguments = new QueryArguments(
            Argument("first", Ref("Int")),
            Argument("after", Ref("String")),
            Argument("last", Ref("Int")),
            Argument("before", Ref("String")));

        if (whereArgs is not null)
        {
            arguments.Add(Argument("where", Ref(name + "WhereArgs")));
        }

        from.AddField(new FieldType
        {
            Name = fieldName,
            ResolvedType = Ref(name),
            Arguments = arguments,
            Resolver = new DelegateResolver(async context =>
            {
                // Refused before anything is looked up, not after.
                if (context.GetArgument<int?>("last") is not null || context.GetArgument<string?>("before") is not null)
                {
                    throw new GraphQLInputException("Backward pagination is not supported. Use first and after.");
                }

                var source = await resolve(context).ConfigureAwait(false);

                return source is Elephentity.Runtime.Query.IEntityQuery<object> rows
                    ? await Connections.ResolveAsync(
                        rows,
                        context.GetArgument<int?>("first"),
                        context.GetArgument<string?>("after"),
                        false,
                        options,
                        context.CancellationToken).ConfigureAwait(false)
                    : throw new InvalidOperationException($"{fromName}.{fieldName} did not resolve to an IEntityQuery<object>; a typed query needs EntityQueries.AsObjects.");
            }),
        });
    }
}
