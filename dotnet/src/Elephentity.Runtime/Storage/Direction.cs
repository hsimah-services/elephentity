namespace Elephentity.Runtime.Storage;

/// <summary>Which way an <see cref="Order" /> term sorts.</summary>
public enum Direction
{
    /// <summary>Smallest first.</summary>
    Ascending,

    /// <summary>Largest first.</summary>
    Descending,
}

/// <summary>The wire spelling of a <see cref="Direction" />.</summary>
public static class DirectionExtensions
{
    /// <summary>The spelling used in manifests: <c>asc</c> or <c>desc</c>.</summary>
    /// <param name="direction">The direction.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this Direction direction) => direction switch
    {
        Direction.Ascending => "asc",
        Direction.Descending => "desc",
        _ => throw new ArgumentOutOfRangeException(nameof(direction)),
    };

    /// <summary>The direction a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The direction.</returns>
    /// <exception cref="ArgumentException">The name is not a direction.</exception>
    public static Direction FromWire(string value) => value switch
    {
        "asc" => Direction.Ascending,
        "desc" => Direction.Descending,
        _ => throw new ArgumentException($"\"{value}\" is not a direction.", nameof(value)),
    };
}
