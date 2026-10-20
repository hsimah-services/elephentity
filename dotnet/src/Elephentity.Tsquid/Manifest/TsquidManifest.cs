namespace Elephentity.Tsquid.Manifest;

/// <summary>Where a route field is read from.</summary>
public enum FieldLocation
{
    /// <summary>A <c>:param</c> segment of the path.</summary>
    Path,

    /// <summary>The query string.</summary>
    Query,
}

/// <summary>The scalar a field is parsed as.</summary>
public enum FieldKind
{
    /// <summary>Taken as it is, including the empty string.</summary>
    Text,

    /// <summary>An integer matching <c>^-?[0-9]+$</c> whose magnitude is at most 2<sup>53</sup>−1.</summary>
    Whole,

    /// <summary>Exactly <c>true</c> or <c>false</c>.</summary>
    Flag,
}

/// <summary>
/// One field of a route's input.
/// </summary>
/// <param name="Kind">The scalar.</param>
/// <param name="Array">Whether it takes every value of a repeated key (<c>string[]</c>) rather than exactly one.</param>
/// <param name="Optional">Whether it may be absent.</param>
/// <param name="In">Where it is read from.</param>
public sealed record RouteField(FieldKind Kind, bool Array, bool Optional, FieldLocation In);

/// <summary>
/// How one GraphQL variable of a route's query is filled.
/// </summary>
/// <param name="Input">The input field that binds it, or <see langword="null" /> when nothing in the URL does.</param>
/// <param name="HasDefault">Whether the manifest carries a default (which may itself be JSON <c>null</c>).</param>
/// <param name="Default">
/// The default as plain values: <see cref="string" />, <see cref="long" />, <see cref="double" />, <see cref="bool" />,
/// <see langword="null" />, <c>List&lt;object?&gt;</c> or <c>Dictionary&lt;string, object?&gt;</c>.
/// </param>
public sealed record VariableBinding(string? Input, bool HasDefault, object? Default);

/// <summary>
/// A route's query, run verbatim.
/// </summary>
/// <param name="Operation">The operation name.</param>
/// <param name="Id">The persisted-query id.</param>
/// <param name="Text">The query text, exactly as the client's compiler produced it (Relay adds <c>id</c> and <c>__typename</c>).</param>
/// <param name="Variables">Every declared variable and how it is bound.</param>
public sealed record RouteQuery(string Operation, string Id, string Text, IReadOnlyDictionary<string, VariableBinding> Variables);

/// <summary>
/// One route of the app.
/// </summary>
/// <param name="Name">The route's name.</param>
/// <param name="Path">The URL pattern: static segments and <c>:param</c> segments.</param>
/// <param name="Ssr">Whether the server inlines data for it. A route with <see langword="false" /> still takes part in matching.</param>
/// <param name="Fields">The input fields by name.</param>
/// <param name="Query">The query the server runs, if any.</param>
public sealed record TsquidRoute(
    string Name,
    string Path,
    bool Ssr,
    IReadOnlyDictionary<string, RouteField> Fields,
    RouteQuery? Query = null);

/// <summary>The route a URL selected, and the input the URL gave it.</summary>
/// <param name="Route">The route.</param>
/// <param name="Input">The parsed input: <see cref="string" />, <see cref="long" />, <see cref="bool" />, or a list of one of them.</param>
public sealed record RouteMatch(TsquidRoute Route, IReadOnlyDictionary<string, object?> Input);
