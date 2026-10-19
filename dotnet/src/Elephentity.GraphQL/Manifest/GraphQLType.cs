using GraphQL.Types;

namespace Elephentity.GraphQL.Manifest;

/// <summary>
/// A GraphQL type reference: a name, plus whether it is non-null and whether it is a list.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\GraphQLType</c>. As there, <see cref="NonNull" /> wraps the list when there is one and the
/// list's items are nullable: <c>[ID]</c>, or <c>[ID]!</c>.
/// </remarks>
/// <param name="Name">The named type.</param>
/// <param name="NonNull">Whether the reference is <c>!</c>.</param>
/// <param name="List">Whether it is a list of <paramref name="Name" />.</param>
public sealed record GraphQLType(string Name, bool NonNull = false, bool List = false)
{
    internal IGraphType ToGraphType()
    {
        IGraphType type = new GraphQLTypeReference(Name);

        if (List)
        {
            type = new ListGraphType(type);
        }

        return NonNull ? new NonNullGraphType(type) : type;
    }
}
