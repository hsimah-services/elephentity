namespace Elephentity.Cosmos.Query;

/// <summary>
/// "This property is one of these values": what an edge filter becomes once any other document that has to be read to
/// answer it has been.
/// </summary>
/// <remarks>
/// A property on the queried document's own side (<c>c.postId IN (…)</c>) or its id (<c>c.id IN (…)</c>), the two shapes an
/// edge ends up as. A join document or a reference on the other entity's side cannot be joined to in Cosmos, so the
/// adaptor reads it first and hands what it found here.
/// </remarks>
/// <param name="Property">The document property; <c>id</c> for the id.</param>
/// <param name="Values">The accepted values.</param>
internal sealed record LinkConstraint(string Property, IReadOnlyList<string> Values);
