using Elephentity.GraphQL.Manifest;
using Elephentity.GraphQL.Relay;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using GraphQL;
using GraphQL.Types;

namespace Elephentity.GraphQL.Schema;

internal sealed partial class SchemaAssembler
{
    private const string ClientMutationId = "clientMutationId";

    /// <summary>What a mutation field resolves to: the row it touched, and the id to evict for a delete.</summary>
    private sealed record Payload(MutationResult? Result, string? DeletedId, string? ClientMutationId);

    private static FieldType Delete(RootFieldEntry root) => new()
    {
        Name = "delete" + root.Type,
        ResolvedType = Ref("Delete" + root.Type + "Payload"),
        Arguments = new QueryArguments(Argument("input", new NonNullGraphType(Ref("Delete" + root.Type + "Input")))),
        Resolver = new DelegateResolver(async context =>
        {
            var input = Input(context);
            var id = Identifier(input, root.Type);

            await Gateway(context).DeleteAsync(root.Entity, id, context.CancellationToken).ConfigureAwait(false);

            // The global form, because it is the id the client cached under and so the one it has to evict.
            return new Payload(null, GlobalId.Encode(root.Type, id), ClientId(input));
        }),
    };

    private void Mutation(MutationEntry entry)
    {
        var inputs = entry.Inputs.ToDictionary(pair => pair.Key, pair => pair.Value);
        var type = TypeFor(entry.Entity);
        var name = entry.Name;

        AddPayload(name, [(LowerFirst(type), Ref(type), context => ((Payload)context.Source!).Result?.Entity)]);
        AddInput(name, inputs);

        mutation.AddField(new FieldType
        {
            Name = name,
            ResolvedType = Ref(UpperFirst(name) + "Payload"),
            Arguments = new QueryArguments(Argument("input", new NonNullGraphType(Ref(UpperFirst(name) + "Input")))),
            Resolver = new DelegateResolver(async context =>
            {
                var input = Input(context);
                var gateway = Gateway(context);
                var token = context.CancellationToken;

                var result = entry.Kind switch
                {
                    MutationKind.Create => await gateway.CreateAsync(entry.Entity, Fields(entry, input), token).ConfigureAwait(false),
                    MutationKind.Update => await gateway.UpdateAsync(entry.Entity, Identifier(input, type), Fields(entry, input), token).ConfigureAwait(false),
                    MutationKind.Action => await gateway
                        .RunActionAsync(entry.Entity, entry.Action ?? throw new InvalidOperationException($"{name} is an action with no action name."), Identifier(input, type), Fields(entry, input), token)
                        .ConfigureAwait(false),
                    _ => throw new InvalidOperationException("Unknown mutation kind."),
                };

                return new Payload(result, null, ClientId(input));
            }),
        });
    }

    /// <summary>Registers the derived delete input and payload types; called once per root, before the delete field is used.</summary>
    private void DeleteTypes()
    {
        foreach (var root in manifest.Roots.Values)
        {
            AddPayload("delete" + root.Type, [("deletedId", Ref("ID"), context => ((Payload)context.Source!).DeletedId)]);
            AddInput("delete" + root.Type, new Dictionary<string, GraphQLType> { ["id"] = new("ID", NonNull: true) });
        }
    }

    private void AddPayload(string mutationName, IEnumerable<(string Name, IGraphType Type, Func<IResolveFieldContext, object?> Read)> outputs)
    {
        var payload = new ObjectGraphType { Name = UpperFirst(mutationName) + "Payload" };

        foreach (var (field, type, read) in outputs)
        {
            payload.AddField(Field(field, type, resolver: new DelegateResolver(read)));
        }

        payload.AddField(Field(ClientMutationId, Ref("String"), resolver: new DelegateResolver(context => ((Payload)context.Source!).ClientMutationId)));
        Add(payload);
    }

    private void AddInput(string mutationName, IReadOnlyDictionary<string, GraphQLType> fields)
    {
        var input = new InputObjectGraphType { Name = UpperFirst(mutationName) + "Input" };

        foreach (var (field, type) in fields)
        {
            input.AddField(Field(field, type.ToGraphType()));
        }

        input.AddField(Field(ClientMutationId, Ref("String")));
        Add(input);
    }

    private static Dictionary<string, object?> Input(IResolveFieldContext context) =>
        context.Arguments is not null && context.Arguments.TryGetValue("input", out var input) && input.Value is IDictionary<string, object?> values
            ? new Dictionary<string, object?>(values)
            : [];

    private static string? ClientId(Dictionary<string, object?> input) => input.TryGetValue(ClientMutationId, out var id) ? id as string : null;

    // A client only ever holds the global form, so that is what arrives. A raw row id still resolves.
    private static EntityId Identifier(Dictionary<string, object?> input, string type) =>
        GlobalId.EntityIdOf(input.GetValueOrDefault("id"), type)
        ?? throw new GraphQLInputException("This mutation needs an id.");

    // Everything but the id and the client's own bookkeeping, with any global id unwrapped: an edge is written by naming the row it
    // points at, and the id the client has for that row is the global one.
    private Dictionary<string, object?> Fields(MutationEntry entry, Dictionary<string, object?> input)
    {
        var fields = new Dictionary<string, object?>();

        foreach (var (name, value) in input)
        {
            if (name is "id" or ClientMutationId)
            {
                continue;
            }

            fields[name] = entry.Inputs.TryGetValue(name, out var declared) && declared.Name == "ID"
                ? Unwrap(value, InputType(entry, name))
                : value;
        }

        return fields;
    }

    private static object? Unwrap(object? value, string? expectedType) => value switch
    {
        System.Collections.IEnumerable list when value is not string => list.Cast<object?>().Select(id => GlobalId.Raw(id, expectedType)).ToList(),
        _ => GlobalId.Raw(value, expectedType),
    };

    // The type an ID input must belong to: the type of the field it names, or the target of the edge it names. An action's
    // arguments are free-standing, so nothing is expected of them.
    private string? InputType(MutationEntry entry, string name)
    {
        if (entry.Kind == MutationKind.Action)
        {
            return null;
        }

        foreach (var obj in manifest.Objects.Values.Where(obj => obj.Entity == entry.Entity))
        {
            if (obj.Fields.TryGetValue(name, out var field) && manifest.Objects.ContainsKey(field.Type.Name))
            {
                return field.Type.Name;
            }

            return obj.Connections.TryGetValue(name, out var connection) ? connection.ToType : null;
        }

        return null;
    }

    private string TypeFor(string entity) => manifest.Roots.Values.FirstOrDefault(root => root.Entity == entity)?.Type ?? entity;
}
