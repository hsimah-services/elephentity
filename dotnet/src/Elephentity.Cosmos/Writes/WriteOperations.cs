using System.Text.Json.Nodes;

namespace Elephentity.Cosmos.Writes;

/// <summary>One thing to do to one document, staged for a transactional batch.</summary>
/// <param name="Entity">The entity or edge it concerns, for messages.</param>
/// <param name="Id">The document's id.</param>
internal abstract record StagedOperation(string Entity, string Id);

/// <summary>Create a document; fails if the id or a unique key is taken.</summary>
internal sealed record CreateOperation(string Entity, string Id, JsonObject Document) : StagedOperation(Entity, Id);

/// <summary>Create a document or replace it; making a link twice is one document.</summary>
internal sealed record UpsertOperation(string Entity, string Id, JsonObject Document) : StagedOperation(Entity, Id);

/// <summary>Set some properties of an existing document.</summary>
/// <param name="Entity">The entity, for messages.</param>
/// <param name="Id">The document.</param>
/// <param name="Sets">Property to new value (<see langword="null" /> clears it).</param>
/// <param name="Guard">Only when this property currently holds this value, or <see langword="null" /> for always.</param>
/// <param name="Optional">Whether a missing document, or a failed guard, is not an error (an unlink of nothing).</param>
/// <param name="Purpose">What the patch is for, for messages: <c>update</c> or <c>link</c> or <c>unlink</c>.</param>
internal sealed record PatchOperation(
    string Entity,
    string Id,
    Dictionary<string, JsonNode?> Sets,
    (string Property, string Value)? Guard,
    bool Optional,
    string Purpose) : StagedOperation(Entity, Id);

/// <summary>Delete a document; one that is already gone is not an error, as deleting no rows is not in SQL.</summary>
internal sealed record DeleteOperation(string Entity, string Id) : StagedOperation(Entity, Id);
