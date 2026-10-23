namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// One column of a table. Ports <c>Eleph\SQLite\Sql\Column</c>.
/// </summary>
/// <param name="Name">The column's name.</param>
/// <param name="Type">The SQL type: <c>TEXT</c>, <c>INTEGER</c> or <c>REAL</c>.</param>
/// <param name="Nullable">Whether the column accepts <c>NULL</c>.</param>
/// <param name="AutoIncrement">Whether it is the <c>INTEGER PRIMARY KEY AUTOINCREMENT</c> identity.</param>
/// <param name="Default">The SQL literal of its <c>DEFAULT</c>, if it has one.</param>
public sealed record SqliteColumn(
    string Name,
    string Type,
    bool Nullable = false,
    bool AutoIncrement = false,
    string? Default = null);
