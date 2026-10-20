using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Relay;
using Elephentity.Runtime.Query;
using GraphQL;
using GraphQL.Types;

namespace Elephentity.GraphQL.Schema;

internal sealed partial class SchemaAssembler
{
    private void RootFields(RootFieldEntry root)
    {
        query.AddField(new FieldType
        {
            Name = root.ByIdField(),
            ResolvedType = Ref(root.Type),
            Description = $"One {root.Type} by id.",
            Arguments = new QueryArguments(Argument("id", new NonNullGraphType(Ref("ID")))),
            Resolver = new DelegateResolver(async context =>
            {
                // The id a client holds is the global one this type hands out, so it arrives encoded. A raw row id still works.
                var id = GlobalId.EntityIdOf(context.GetArgument<string?>("id"), root.Type);

                return id is null
                    ? null
                    : await Gateway(context).FindAsync(root.Entity, id, context.CancellationToken).ConfigureAwait(false);
            }),
        });

        Connection(
            query,
            "RootQuery",
            root.Type,
            root.CollectionField(),
            null,
            context => ValueTask.FromResult<object?>(Gateway(context).All(root.Entity)));

        mutation.AddField(Delete(root));
    }

    private void Published(QueryFieldEntry published)
    {
        if (published.IsCollection)
        {
            Connection(
                query,
                "RootQuery",
                published.Type,
                published.Field,
                published.Args,
                context => Run(published, context));

            return;
        }

        var arguments = new QueryArguments();

        foreach (var (name, type) in published.Args)
        {
            arguments.Add(Argument(name, type.ToGraphType()));
        }

        query.AddField(new FieldType
        {
            Name = published.Field,
            ResolvedType = Ref(published.Type),
            Description = published.Description ?? string.Empty,
            Arguments = arguments,
            Resolver = new DelegateResolver(context => Run(published, context)),
        });
    }

    private static async ValueTask<object?> Run(QueryFieldEntry published, IResolveFieldContext context)
    {
        var args = new Dictionary<string, object?>();

        // A collection's own arguments sit in `where`, beside the paging ones; a single row's are the field's.
        Dictionary<string, object?>? source = null;

        if (published.IsCollection)
        {
            if (context.Arguments is not null && context.Arguments.TryGetValue("where", out var where) && where.Value is IDictionary<string, object?> values)
            {
                source = new Dictionary<string, object?>(values);
            }
        }
        else if (context.Arguments is not null)
        {
            source = context.Arguments.ToDictionary(pair => pair.Key, pair => pair.Value.Value);
        }

        foreach (var (name, type) in published.Args)
        {
            if (source is not null && source.TryGetValue(name, out var value))
            {
                args[name] = type.Name == "ID" ? RawIds(value) : value;
            }
        }

        return await Gateway(context)
            .RunQueryAsync(published.Entity, published.Query, args, context.CancellationToken)
            .ConfigureAwait(false);
    }

    // A client only ever sees the global form, so an argument a spec typed `id` would otherwise be matched against a value no row
    // holds. Decoding is strict: anything that is not one of ours passes through exactly as it arrived.
    private static object? RawIds(object? value) => value switch
    {
        System.Collections.IEnumerable list when value is not string => list.Cast<object?>().Select(item => GlobalId.Raw(item)).ToList(),
        _ => GlobalId.Raw(value),
    };

    private async ValueTask<object?> NodeAsync(IResolveFieldContext context)
    {
        var encoded = context.GetArgument<string>("id");
        var decoded = GlobalId.Decode(encoded);

        if (decoded is null || !manifest.Objects.TryGetValue(decoded.Type, out var entry))
        {
            throw new GraphQLInputException("Invalid node ID.");
        }

        var id = GlobalId.EntityIdOf(encoded, decoded.Type) ?? throw new GraphQLInputException("Invalid node ID.");

        return await Gateway(context).FindAsync(entry.Entity, id, context.CancellationToken).ConfigureAwait(false);
    }
}
