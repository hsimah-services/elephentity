namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// An index on a table. Ports <c>Eleph\SQLite\Sql\Index</c>.
/// </summary>
/// <param name="Name">The index's name. SQLite index names are database-wide.</param>
/// <param name="Columns">The columns it covers, in order.</param>
/// <param name="Unique">Whether it enforces uniqueness.</param>
public sealed record SqliteIndex(string Name, IReadOnlyList<string> Columns, bool Unique = false);
