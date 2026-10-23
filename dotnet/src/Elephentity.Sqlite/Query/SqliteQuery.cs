namespace Elephentity.Sqlite.Query;

/// <summary>A parameterised statement: its text, and the values the <c>@p0…</c> placeholders stand for.</summary>
/// <param name="Sql">The statement.</param>
/// <param name="Bindings">The parameter values by placeholder name, without the <c>@</c>.</param>
internal sealed record SqliteQuery(string Sql, IReadOnlyDictionary<string, object?> Bindings);
