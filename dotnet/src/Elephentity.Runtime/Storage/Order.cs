namespace Elephentity.Runtime.Storage;

/// <summary>One term of a sort: a field and a direction.</summary>
public sealed record Order
{
    private Order(string field, Direction direction)
    {
        Field = field;
        Direction = direction;
    }

    /// <summary>The field to sort by.</summary>
    public string Field { get; }

    /// <summary>Which way to sort it.</summary>
    public Direction Direction { get; }

    /// <summary>Sort <paramref name="field" /> smallest first.</summary>
    /// <param name="field">The field.</param>
    /// <returns>The term.</returns>
    public static Order Ascending(string field) => new(field, Direction.Ascending);

    /// <summary>Sort <paramref name="field" /> largest first.</summary>
    /// <param name="field">The field.</param>
    /// <returns>The term.</returns>
    public static Order Descending(string field) => new(field, Direction.Descending);
}
