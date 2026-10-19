using System.Text.RegularExpressions;

namespace Elephentity.Cosmos;

/// <summary>The names the container itself uses in every document.</summary>
public static partial class DocumentProperties
{
    /// <summary>The document id: the entity's id.</summary>
    public const string Id = "id";

    /// <summary>The partition key property, <c>/pk</c>.</summary>
    public const string PartitionKey = "pk";

    /// <summary>The entity's name, or an edge's join type.</summary>
    public const string Type = "type";

    /// <summary>The prefix of a join document's <c>type</c>.</summary>
    public const string EdgeTypePrefix = "__edge:";

    /// <summary>A join document's owning row.</summary>
    public const string From = "from";

    /// <summary>A join document's target row.</summary>
    public const string To = "to";

    /// <summary>The name of unique-key slot <paramref name="slot" />: <c>_u0</c>, <c>_u1</c>, …</summary>
    /// <param name="slot">The slot.</param>
    /// <returns>The property name.</returns>
    public static string UniqueSlot(int slot) => $"_u{slot}";

    /// <summary>Whether <paramref name="name" /> is one a field or edge may not use.</summary>
    /// <param name="name">The property name.</param>
    /// <returns><see langword="true" /> when it is the container's, Cosmos's or a unique slot's.</returns>
    /// <remarks><c>from</c> and <c>to</c> are not reserved: they exist only on join documents, which are a type of their own.</remarks>
    public static bool IsReserved(string name) =>
        name is Id or PartitionKey or Type || name.StartsWith('_');

    /// <summary>Whether <paramref name="name" /> can be written into a query as a property without escaping.</summary>
    /// <param name="name">The property name.</param>
    /// <returns><see langword="true" /> for letters, digits and underscores, not starting with a digit.</returns>
    public static bool IsPlainName(string name) => PlainName().IsMatch(name);

    [GeneratedRegex("^[A-Za-z_][A-Za-z0-9_]*$", RegexOptions.CultureInvariant)]
    private static partial Regex PlainName();
}
