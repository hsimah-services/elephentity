namespace Elephentity.Runtime.Storage;

/// <summary>
/// One condition on a field. Filters within a <see cref="Criteria" /> are conjunctive.
/// </summary>
/// <remarks>
/// A value is a scalar (<see cref="string" />, <see cref="long" />, <see cref="double" /> or
/// <see cref="bool" />), a list of scalars for <see cref="Comparison.In" /> and
/// <see cref="Comparison.NotIn" />, or nothing for the null checks. Other integer widths are
/// widened to <see cref="long" />.
/// </remarks>
public sealed record Filter
{
    private Filter(string field, Comparison comparison, object? value)
    {
        Field = field;
        Comparison = comparison;
        Value = value;
    }

    /// <summary>The field the condition is on.</summary>
    public string Field { get; }

    /// <summary>How the field is compared.</summary>
    public Comparison Comparison { get; }

    /// <summary>What it is compared with; <see langword="null" /> for the null checks.</summary>
    public object? Value { get; }

    /// <summary>The field equals <paramref name="value" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">A scalar.</param>
    /// <returns>The filter.</returns>
    public static Filter Equal(string field, object value) => new(field, Comparison.Equal, Scalar(value));

    /// <summary>The field differs from <paramref name="value" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">A scalar.</param>
    /// <returns>The filter.</returns>
    public static Filter NotEqual(string field, object value) => new(field, Comparison.NotEqual, Scalar(value));

    /// <summary>The field is less than <paramref name="value" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">A scalar.</param>
    /// <returns>The filter.</returns>
    public static Filter LessThan(string field, object value) => new(field, Comparison.LessThan, Scalar(value));

    /// <summary>The field is less than or equal to <paramref name="value" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">A scalar.</param>
    /// <returns>The filter.</returns>
    public static Filter LessThanOrEqual(string field, object value) => new(field, Comparison.LessThanOrEqual, Scalar(value));

    /// <summary>The field is greater than <paramref name="value" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">A scalar.</param>
    /// <returns>The filter.</returns>
    public static Filter GreaterThan(string field, object value) => new(field, Comparison.GreaterThan, Scalar(value));

    /// <summary>The field is greater than or equal to <paramref name="value" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">A scalar.</param>
    /// <returns>The filter.</returns>
    public static Filter GreaterThanOrEqual(string field, object value) => new(field, Comparison.GreaterThanOrEqual, Scalar(value));

    /// <summary>The field is one of <paramref name="values" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="values">Scalars.</param>
    /// <returns>The filter.</returns>
    public static Filter In(string field, IEnumerable<object> values) => new(field, Comparison.In, Scalars(values));

    /// <summary>The field is none of <paramref name="values" />.</summary>
    /// <param name="field">The field.</param>
    /// <param name="values">Scalars.</param>
    /// <returns>The filter.</returns>
    public static Filter NotIn(string field, IEnumerable<object> values) => new(field, Comparison.NotIn, Scalars(values));

    /// <summary>The field contains <paramref name="value" /> as text.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">The text to look for.</param>
    /// <returns>The filter.</returns>
    public static Filter Contains(string field, string value) => new(field, Comparison.Contains, value);

    /// <summary>The field starts with <paramref name="value" /> as text.</summary>
    /// <param name="field">The field.</param>
    /// <param name="value">The prefix.</param>
    /// <returns>The filter.</returns>
    public static Filter StartsWith(string field, string value) => new(field, Comparison.StartsWith, value);

    /// <summary>The field is null.</summary>
    /// <param name="field">The field.</param>
    /// <returns>The filter.</returns>
    public static Filter IsNull(string field) => new(field, Comparison.IsNull, null);

    /// <summary>The field is not null.</summary>
    /// <param name="field">The field.</param>
    /// <returns>The filter.</returns>
    public static Filter IsNotNull(string field) => new(field, Comparison.IsNotNull, null);

    private static IReadOnlyList<object> Scalars(IEnumerable<object> values) =>
        [.. values.Select(Scalar)];

    private static object Scalar(object? value) => value switch
    {
        string or long or double or bool => value,
        int or short or sbyte or byte or ushort => Convert.ToInt64(value, System.Globalization.CultureInfo.InvariantCulture),
        uint number => (long)number,
        float number => (double)number,
        _ => throw new ArgumentException(
            $"A filter value must be a string, a number or a bool, not {value?.GetType().Name ?? "null"}.",
            nameof(value)),
    };
}
