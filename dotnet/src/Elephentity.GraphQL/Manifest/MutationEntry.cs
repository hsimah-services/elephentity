namespace Elephentity.GraphQL.Manifest;

/// <summary>What a mutation does.</summary>
public enum MutationKind
{
    /// <summary>Creates a row from the settable fields.</summary>
    Create,

    /// <summary>Updates a row from the settable fields.</summary>
    Update,

    /// <summary>Deletes a row by id; withheld by an entity's <c>delete: false</c>.</summary>
    Delete,

    /// <summary>Runs a declared action.</summary>
    Action,
}

/// <summary>
/// One GraphQL mutation.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Manifest\MutationEntry</c>. Delete takes an id and nothing else, so its entry carries only that.
/// </remarks>
/// <param name="Name">The mutation's name.</param>
/// <param name="Kind">What it does.</param>
/// <param name="Entity">The entity it writes.</param>
/// <param name="Inputs">The input fields by name.</param>
/// <param name="Action">The action's name, for <see cref="MutationKind.Action" />.</param>
/// <param name="Description">The description, or <see langword="null" />.</param>
public sealed record MutationEntry(
    string Name,
    MutationKind Kind,
    string Entity,
    IReadOnlyDictionary<string, GraphQLType> Inputs,
    string? Action = null,
    string? Description = null);
