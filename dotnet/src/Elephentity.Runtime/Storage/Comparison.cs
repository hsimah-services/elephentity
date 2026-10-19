namespace Elephentity.Runtime.Storage;

/// <summary>How a <see cref="Filter" /> compares a field with its value.</summary>
public enum Comparison
{
    /// <summary>The field equals the value.</summary>
    Equal,

    /// <summary>The field differs from the value.</summary>
    NotEqual,

    /// <summary>The field is less than the value.</summary>
    LessThan,

    /// <summary>The field is less than or equal to the value.</summary>
    LessThanOrEqual,

    /// <summary>The field is greater than the value.</summary>
    GreaterThan,

    /// <summary>The field is greater than or equal to the value.</summary>
    GreaterThanOrEqual,

    /// <summary>The field is one of a list of values.</summary>
    In,

    /// <summary>The field is none of a list of values.</summary>
    NotIn,

    /// <summary>The field contains the value as text.</summary>
    Contains,

    /// <summary>The field starts with the value as text.</summary>
    StartsWith,

    /// <summary>The field is null.</summary>
    IsNull,

    /// <summary>The field is not null.</summary>
    IsNotNull,
}

/// <summary>The wire spelling of a <see cref="Comparison" />.</summary>
public static class ComparisonExtensions
{
    /// <summary>The spelling used in manifests: <c>eq</c>, <c>lte</c>, <c>notIn</c>, and so on.</summary>
    /// <param name="comparison">The comparison.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this Comparison comparison) => comparison switch
    {
        Comparison.Equal => "eq",
        Comparison.NotEqual => "neq",
        Comparison.LessThan => "lt",
        Comparison.LessThanOrEqual => "lte",
        Comparison.GreaterThan => "gt",
        Comparison.GreaterThanOrEqual => "gte",
        Comparison.In => "in",
        Comparison.NotIn => "notIn",
        Comparison.Contains => "contains",
        Comparison.StartsWith => "startsWith",
        Comparison.IsNull => "isNull",
        Comparison.IsNotNull => "isNotNull",
        _ => throw new ArgumentOutOfRangeException(nameof(comparison)),
    };

    /// <summary>The comparison a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The comparison.</returns>
    /// <exception cref="ArgumentException">The name is not a comparison.</exception>
    public static Comparison FromWire(string value) => value switch
    {
        "eq" => Comparison.Equal,
        "neq" => Comparison.NotEqual,
        "lt" => Comparison.LessThan,
        "lte" => Comparison.LessThanOrEqual,
        "gt" => Comparison.GreaterThan,
        "gte" => Comparison.GreaterThanOrEqual,
        "in" => Comparison.In,
        "notIn" => Comparison.NotIn,
        "contains" => Comparison.Contains,
        "startsWith" => Comparison.StartsWith,
        "isNull" => Comparison.IsNull,
        "isNotNull" => Comparison.IsNotNull,
        _ => throw new ArgumentException($"\"{value}\" is not a comparison.", nameof(value)),
    };
}
