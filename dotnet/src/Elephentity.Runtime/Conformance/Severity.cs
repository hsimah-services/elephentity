namespace Elephentity.Runtime.Conformance;

/// <summary>
/// How seriously a verifier means a signal.
/// </summary>
/// <remarks>
/// Only <see cref="Error" /> fails <c>eleph check</c>. A verifier that cannot check something (a
/// shape it does not recognise, a feature it does not cover) says so at <see cref="Warning" /> or
/// <see cref="Info" /> rather than staying silent, which would look identical to a surface that
/// conforms.
/// </remarks>
public enum Severity
{
    /// <summary>The surface does not conform.</summary>
    Error,

    /// <summary>Something the verifier could not check, or doubts.</summary>
    Warning,

    /// <summary>Worth knowing, not worth failing for.</summary>
    Info,
}

/// <summary>The wire spelling of a <see cref="Severity" />.</summary>
public static class SeverityExtensions
{
    /// <summary>The spelling used in reports: <c>error</c>, <c>warning</c> or <c>info</c>.</summary>
    /// <param name="severity">The severity.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this Severity severity) => severity switch
    {
        Severity.Error => "error",
        Severity.Warning => "warning",
        Severity.Info => "info",
        _ => throw new ArgumentOutOfRangeException(nameof(severity)),
    };

    /// <summary>The severity a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The severity.</returns>
    /// <exception cref="ArgumentException">The name is not a severity.</exception>
    public static Severity FromWire(string value) => value switch
    {
        "error" => Severity.Error,
        "warning" => Severity.Warning,
        "info" => Severity.Info,
        _ => throw new ArgumentException($"\"{value}\" is not a severity.", nameof(value)),
    };
}
