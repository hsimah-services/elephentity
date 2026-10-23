namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// A table: its columns and indexes. Ports <c>Eleph\SQLite\Sql\TableSchema</c>.
/// </summary>
public sealed class SqliteTable
{
    private readonly Dictionary<string, SqliteColumn> byName;

    /// <summary>A table.</summary>
    /// <param name="name">The table's name, prefix included.</param>
    /// <param name="columns">The columns, in declaration order.</param>
    /// <param name="indexes">The indexes.</param>
    /// <param name="primaryKey">The primary key column; empty for a join table, which has none.</param>
    /// <exception cref="ArgumentException">A name is not a valid identifier, or a column is declared twice.</exception>
    public SqliteTable(
        string name,
        IReadOnlyList<SqliteColumn> columns,
        IReadOnlyList<SqliteIndex>? indexes = null,
        string primaryKey = "id")
    {
        SqliteNames.Require(name, "table name");

        byName = new Dictionary<string, SqliteColumn>(StringComparer.Ordinal);

        foreach (var column in columns)
        {
            SqliteNames.Require(column.Name, "column name");

            if (!byName.TryAdd(column.Name, column))
            {
                throw new ArgumentException($"Column {name}.{column.Name} is declared more than once.", nameof(columns));
            }
        }

        foreach (var index in indexes ?? [])
        {
            SqliteNames.Require(index.Name, "index name");

            foreach (var column in index.Columns)
            {
                if (!byName.ContainsKey(column))
                {
                    throw new ArgumentException($"Index {index.Name} covers {column}, which {name} does not have.", nameof(indexes));
                }
            }
        }

        if (primaryKey.Length > 0 && !byName.ContainsKey(primaryKey))
        {
            throw new ArgumentException($"{name} has no primary key column {primaryKey}.", nameof(primaryKey));
        }

        Name = name;
        Columns = columns;
        Indexes = indexes ?? [];
        PrimaryKey = primaryKey;
    }

    /// <summary>The table's name.</summary>
    public string Name { get; }

    /// <summary>The columns, in declaration order.</summary>
    public IReadOnlyList<SqliteColumn> Columns { get; }

    /// <summary>The indexes.</summary>
    public IReadOnlyList<SqliteIndex> Indexes { get; }

    /// <summary>The primary key column; empty for a join table.</summary>
    public string PrimaryKey { get; }

    /// <summary>The column called <paramref name="name" />, or <see langword="null" />.</summary>
    /// <param name="name">The column's name.</param>
    /// <returns>The column, if there is one.</returns>
    public SqliteColumn? Column(string name) => byName.GetValueOrDefault(name);

    /// <summary>This table renamed, and its indexes with it: index names embed the table name, so they move together.</summary>
    /// <param name="prefix">What to put in front of both.</param>
    /// <returns>The renamed table.</returns>
    internal SqliteTable WithPrefix(string prefix) =>
        new(
            prefix + Name,
            Columns,
            [.. Indexes.Select(index => index with { Name = prefix + index.Name })],
            PrimaryKey);
}
