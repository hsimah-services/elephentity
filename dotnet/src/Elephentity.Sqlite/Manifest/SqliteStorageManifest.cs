using Elephentity.Runtime.Storage;

namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// The physical schema, compiled. Ports <c>Eleph\SQLite\Manifest\StorageManifest</c>.
/// </summary>
/// <remarks>
/// The adaptor needs table definitions, a field-to-column map and where every edge lives. All three are derived from the IR,
/// and the IR is a build-time artefact, so they are worked out once by <c>eleph-gen-sqlite</c> and written down as code that
/// builds this, exactly as the GraphQL surface is. The initial schema travels with it as <see cref="Install" />, which
/// <see cref="SqliteInstaller" /> runs: PHP generates a separate <c>install.php</c> closure for the same job.
/// </remarks>
public sealed class SqliteStorageManifest
{
    /// <summary>A manifest.</summary>
    /// <param name="tables">Entity name to the table its rows live in.</param>
    /// <param name="placements">Where every edge lives, keyed <c>Entity.edge</c>.</param>
    /// <param name="columns">Entity name to field name to column name.</param>
    /// <param name="joinTables">
    /// Many-to-many link tables, which belong to an edge rather than an entity and so cannot live in the entity-keyed map the
    /// adaptor looks rows up in. They still have to be created.
    /// </param>
    /// <param name="install">The statements that create the initial schema, in order.</param>
    /// <exception cref="ArgumentException">The manifest contradicts itself.</exception>
    public SqliteStorageManifest(
        IReadOnlyDictionary<string, SqliteTable> tables,
        IReadOnlyDictionary<string, SqliteEdgePlacement>? placements = null,
        IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>>? columns = null,
        IReadOnlyDictionary<string, SqliteTable>? joinTables = null,
        IReadOnlyList<SqliteStatement>? install = null)
    {
        Tables = tables;
        Placements = placements ?? new Dictionary<string, SqliteEdgePlacement>();
        Columns = columns ?? new Dictionary<string, IReadOnlyDictionary<string, string>>();
        JoinTables = joinTables ?? new Dictionary<string, SqliteTable>();
        Install = install ?? [];

        foreach (var (entity, fields) in Columns)
        {
            if (!Tables.TryGetValue(entity, out var table))
            {
                throw new ArgumentException($"Columns are mapped for {entity}, which has no table.", nameof(columns));
            }

            foreach (var (field, column) in fields)
            {
                if (table.Column(column) is null)
                {
                    throw new ArgumentException($"{entity}.{field} maps to {column}, which {table.Name} does not have.", nameof(columns));
                }
            }
        }

        var known = EveryTable().Select(table => table.Name).ToHashSet(StringComparer.Ordinal);

        foreach (var (key, placement) in Placements)
        {
            if (key != $"{placement.Entity}.{placement.Edge}")
            {
                throw new ArgumentException($"The placement of {placement.Entity}.{placement.Edge} is keyed {key}.", nameof(placements));
            }

            SqliteNames.Require(placement.LocalColumn, "column name");

            if (placement.TargetColumn is not null)
            {
                SqliteNames.Require(placement.TargetColumn, "column name");
            }

            if (!known.Contains(placement.Table) || !known.Contains(placement.TargetTable))
            {
                throw new ArgumentException($"{key} is placed in {placement.Table} and points at {placement.TargetTable}; the manifest has no such table.", nameof(placements));
            }
        }
    }

    /// <summary>Entity name to the table its rows live in.</summary>
    public IReadOnlyDictionary<string, SqliteTable> Tables { get; }

    /// <summary>Where every edge lives, keyed <c>Entity.edge</c>.</summary>
    public IReadOnlyDictionary<string, SqliteEdgePlacement> Placements { get; }

    /// <summary>Entity name to field name to column name.</summary>
    public IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>> Columns { get; }

    /// <summary>Many-to-many link tables, keyed by table name.</summary>
    public IReadOnlyDictionary<string, SqliteTable> JoinTables { get; }

    /// <summary>The statements that create the initial schema, in order.</summary>
    public IReadOnlyList<SqliteStatement> Install { get; }

    /// <summary>
    /// Every table the schema needs, entity and join alike, by table name.
    /// </summary>
    /// <returns>The tables, ordered by name.</returns>
    public IReadOnlyList<SqliteTable> EveryTable() =>
    [
        .. JoinTables.Values
            .Concat(Tables.Values)
            .GroupBy(table => table.Name, StringComparer.Ordinal)
            .Select(group => group.Last())
            .OrderBy(table => table.Name, StringComparer.Ordinal),
    ];

    /// <summary>The table <paramref name="entity" />'s rows live in.</summary>
    /// <param name="entity">The entity.</param>
    /// <returns>The table.</returns>
    /// <exception cref="InvalidOperationException">No table is mapped for it.</exception>
    public SqliteTable Table(string entity) =>
        Tables.TryGetValue(entity, out var table)
            ? table
            : throw new InvalidOperationException($"No table is mapped for entity \"{entity}\".");

    /// <summary>Where <c>entity.edge</c> lives.</summary>
    /// <param name="entity">The entity that declares the edge.</param>
    /// <param name="edge">The edge.</param>
    /// <returns>The placement.</returns>
    /// <exception cref="InvalidOperationException">The edge is not mapped.</exception>
    public SqliteEdgePlacement Placement(string entity, string edge) =>
        Placements.TryGetValue($"{entity}.{edge}", out var placement)
            ? placement
            : throw new InvalidOperationException($"Edge {entity}.{edge} is not mapped.");

    /// <summary>
    /// The column <paramref name="field" /> is stored in. A name nothing maps is a link column or a projection the adaptor
    /// added, and is already in database form.
    /// </summary>
    /// <param name="entity">The entity.</param>
    /// <param name="field">The field.</param>
    /// <returns>The column.</returns>
    public string Column(string entity, string field) =>
        Columns.TryGetValue(entity, out var fields) && fields.TryGetValue(field, out var column) ? column : field;

    /// <summary>The field stored in <paramref name="column" />, or the column's own name when no field is.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="column">The column.</param>
    /// <returns>The field.</returns>
    public string Field(string entity, string column)
    {
        if (Columns.TryGetValue(entity, out var fields))
        {
            foreach (var (field, mapped) in fields)
            {
                if (mapped == column)
                {
                    return field;
                }
            }
        }

        return column;
    }

    /// <summary>
    /// This manifest with <paramref name="prefix" /> in front of every table and index name.
    /// </summary>
    /// <remarks>
    /// The compiler has already applied <c>project.storage.tablePrefix</c> to the names in the manifest. This is for an
    /// <em>additional</em> deployment prefix, and goes with the same prefix passed to
    /// <see cref="SqliteInstaller.InstallAsync" />.
    /// </remarks>
    /// <param name="prefix">The prefix; empty returns this manifest.</param>
    /// <returns>The prefixed manifest.</returns>
    /// <exception cref="ArgumentException">The prefix is not a valid identifier.</exception>
    public SqliteStorageManifest WithPrefix(string prefix)
    {
        if (prefix.Length == 0)
        {
            return this;
        }

        SqliteNames.Require(prefix, "table prefix");

        return new SqliteStorageManifest(
            Tables.ToDictionary(entry => entry.Key, entry => entry.Value.WithPrefix(prefix)),
            Placements.ToDictionary(
                entry => entry.Key,
                entry => entry.Value with { Table = prefix + entry.Value.Table, TargetTable = prefix + entry.Value.TargetTable }),
            Columns,
            JoinTables.Values.ToDictionary(table => prefix + table.Name, table => table.WithPrefix(prefix)),
            Install);
    }
}
