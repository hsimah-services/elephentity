using System.Globalization;
using Elephentity.Runtime.Capability;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Sqlite.Manifest;
using Elephentity.Sqlite.Query;
using Microsoft.Data.Sqlite;
using CapabilityKind = Elephentity.Runtime.Capability.Capability;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Sqlite;

/// <summary>
/// <see cref="IStorageAdaptor" /> on SQLite. Ports <c>Eleph\SQLite\SQLiteAdaptor</c> and the <c>Database</c> it runs on.
/// </summary>
/// <remarks>
/// <para>
/// <b>One connection.</b> SQLite allows one writer at a time and a connection is not safe to share between commands that run
/// at once, so every operation on this adaptor takes one lock and the adaptor runs them one after another. A transaction holds
/// the lock for its duration. Create separate adaptors for separate databases, and for tests.
/// </para>
/// <para>
/// <b>Transactions.</b> <see cref="TransactionAsync{T}" /> is <c>BEGIN IMMEDIATE</c> and <c>COMMIT</c>, and one opened inside
/// another is a savepoint, as in PHP, so it rolls back by itself. <see cref="WriteAsync" /> is a transaction of its own, so a
/// batch is atomic. The work must not start tasks that use the adaptor and are still running when it returns.
/// </para>
/// <para>
/// <b>Paging.</b> A page resumes by offset, as in PHP, unless the cursor passed in is a <see cref="KeysetCursor" />, which is
/// honoured as a position and answered with another.
/// </para>
/// <para>
/// <b>Connection settings.</b> On first use the adaptor sets <c>foreign_keys</c>, <c>busy_timeout</c>, <c>journal_mode = WAL</c>,
/// <c>synchronous = FULL</c> and <c>cache_size</c>, as the PHP <c>Database</c> does, whether it opened the connection or was
/// handed one. Foreign keys are not optional: the runtime relies on <c>ON DELETE RESTRICT</c>.
/// </para>
/// </remarks>
public sealed class SqliteAdaptor : IStorageAdaptor, IDisposable
{
    private static readonly string[] Pragmas =
    [
        "PRAGMA foreign_keys = ON",
        "PRAGMA busy_timeout = 3000",
        "PRAGMA journal_mode = WAL",
        "PRAGMA synchronous = FULL",
        "PRAGMA cache_size = -2048",
    ];

    private readonly SemaphoreSlim gate = new(1, 1);
    private readonly AsyncLocal<TransactionState?> ambient = new();
    private readonly SqliteConnection connection;
    private readonly bool owned;
    private readonly SqliteStorageManifest manifest;
    private readonly SqliteQueryCompiler compiler;
    private bool configured;

    /// <summary>An adaptor on a database it opens itself. Does no I/O: the connection opens on first use.</summary>
    /// <param name="connectionString">A <c>Microsoft.Data.Sqlite</c> connection string, such as <c>Data Source=app.db</c>.</param>
    /// <param name="manifest">Where everything lives.</param>
    public SqliteAdaptor(string connectionString, SqliteStorageManifest manifest)
        : this(new SqliteConnection(connectionString), manifest, ownsConnection: true)
    {
    }

    /// <summary>An adaptor on a connection the caller owns. Does no I/O.</summary>
    /// <param name="connection">The connection; the caller disposes it. It is opened on first use if it is closed.</param>
    /// <param name="manifest">Where everything lives.</param>
    public SqliteAdaptor(SqliteConnection connection, SqliteStorageManifest manifest)
        : this(connection, manifest, ownsConnection: false)
    {
    }

    private SqliteAdaptor(SqliteConnection connection, SqliteStorageManifest manifest, bool ownsConnection)
    {
        this.connection = connection;
        this.manifest = manifest;
        owned = ownsConnection;
        compiler = new SqliteQueryCompiler(manifest);
    }

    /// <inheritdoc />
    public Capabilities Capabilities { get; } = new("sqlite", CapabilityKind.Transactions, CapabilityKind.ForeignKeys);

    /// <summary>Disposes the connection this adaptor opened, if it opened one.</summary>
    public void Dispose()
    {
        gate.Dispose();

        if (owned)
        {
            connection.Dispose();
        }
    }

    /// <inheritdoc />
    public Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default) =>
        WithConnection<Record?>(
            async () =>
            {
                var table = manifest.Table(entity);
                var rows = await SelectAsync(
                    $"SELECT * FROM {SqliteNames.Quote(table.Name)} WHERE \"id\" = @p0 LIMIT 1",
                    new Dictionary<string, object?> { ["p0"] = id.Raw() },
                    cancellationToken).ConfigureAwait(false);

                return rows.Count == 0 ? null : ToRecord(entity, rows[0]);
            },
            cancellationToken);

    /// <inheritdoc />
    public Task<IReadOnlyList<Record>> GetManyAsync(
        string entity,
        IReadOnlyList<EntityId> ids,
        CancellationToken cancellationToken = default)
    {
        if (ids.Count == 0)
        {
            return Task.FromResult<IReadOnlyList<Record>>([]);
        }

        return WithConnection<IReadOnlyList<Record>>(
            async () =>
            {
                var table = manifest.Table(entity);
                var bindings = new Dictionary<string, object?>();

                for (var index = 0; index < ids.Count; index++)
                {
                    bindings["p" + index.ToString(CultureInfo.InvariantCulture)] = ids[index].Raw();
                }

                var rows = await SelectAsync(
                    $"SELECT * FROM {SqliteNames.Quote(table.Name)} WHERE \"id\" IN ({string.Join(", ", bindings.Keys.Select(name => "@" + name))})",
                    bindings,
                    cancellationToken).ConfigureAwait(false);

                return [.. rows.Select(row => ToRecord(entity, row))];
            },
            cancellationToken);
    }

    /// <inheritdoc />
    public async Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        if (criteria.Limit is < 0)
        {
            throw new ArgumentOutOfRangeException(nameof(criteria), "Page size must be non-negative.");
        }

        if (criteria.Limit == 0)
        {
            return new Page<Record>([]);
        }

        // The cap is part of the criteria from here on, so the compiler and the page agree on how many rows were asked for.
        if (criteria.Limit is { } requested)
        {
            criteria = criteria.Take(Math.Min(requested, Page<Record>.MaxLimit), criteria.After);
        }

        return await WithConnection(
            async () =>
            {
                var table = manifest.Table(criteria.Entity);
                var query = compiler.Select(table, criteria);
                var rows = await SelectAsync(query.Sql, query.Bindings, cancellationToken).ConfigureAwait(false);
                IReadOnlyList<Record> records = [.. rows.Select(row => ToRecord(criteria.Entity, row))];

                if (criteria.Limit is not { } limit)
                {
                    return new Page<Record>(records);
                }

                // The compiler asked for one row more than the caller wanted. Its presence is the answer to "is there another
                // page", and it is dropped here so nothing above ever sees it.
                var hasMore = records.Count > limit;
                IReadOnlyList<Record> page = [.. records.Take(limit)];

                if (!hasMore)
                {
                    return new Page<Record>(page);
                }

                if (KeysetCursor.From(criteria.After) is not null)
                {
                    var orders = criteria.WithStableOrder().Orders;
                    var last = page[^1];

                    return new Page<Record>(
                        page,
                        new KeysetCursor([.. orders.Take(orders.Count - 1).Select(order => last.Value(order.Field))], last.Id));
                }

                return new Page<Record>(page, new OffsetCursor(OffsetCursor.From(criteria.After).Value + limit));
            },
            cancellationToken).ConfigureAwait(false);
    }

    /// <inheritdoc />
    public Task<long> CountAsync(Criteria criteria, CancellationToken cancellationToken = default) =>
        WithConnection(
            async () =>
            {
                var query = compiler.Count(manifest.Table(criteria.Entity), criteria);
                var rows = await SelectAsync(query.Sql, query.Bindings, cancellationToken).ConfigureAwait(false);

                return Convert.ToInt64(rows[0].Values.First(), CultureInfo.InvariantCulture);
            },
            cancellationToken);

    /// <inheritdoc />
    public Task<WriteResult> WriteAsync(WriteBatch batch, CancellationToken cancellationToken = default) =>
        TransactionAsync(token => ApplyAsync(batch, token), cancellationToken);

    /// <inheritdoc />
    public async Task<T> TransactionAsync<T>(Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken = default)
    {
        if (ambient.Value is { } open)
        {
            return await SavepointAsync(open, work, cancellationToken).ConfigureAwait(false);
        }

        await gate.WaitAsync(cancellationToken).ConfigureAwait(false);

        try
        {
            await EnsureOpenAsync(cancellationToken).ConfigureAwait(false);

            var state = new TransactionState();

            ambient.Value = state;

            try
            {
                await ExecuteAsync("BEGIN IMMEDIATE", null, cancellationToken).ConfigureAwait(false);
                state.Depth = 1;

                try
                {
                    var result = await work(cancellationToken).ConfigureAwait(false);

                    await ExecuteAsync("COMMIT", null, cancellationToken).ConfigureAwait(false);

                    return result;
                }
                catch
                {
                    await RollbackAsync("ROLLBACK").ConfigureAwait(false);

                    throw;
                }
            }
            finally
            {
                ambient.Value = null;
            }
        }
        finally
        {
            gate.Release();
        }
    }

    private async Task<T> SavepointAsync<T>(TransactionState state, Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken)
    {
        var name = "eleph_" + state.Depth.ToString(CultureInfo.InvariantCulture);

        await ExecuteAsync("SAVEPOINT " + name, null, cancellationToken).ConfigureAwait(false);
        state.Depth++;

        try
        {
            var result = await work(cancellationToken).ConfigureAwait(false);

            await ExecuteAsync("RELEASE SAVEPOINT " + name, null, cancellationToken).ConfigureAwait(false);

            return result;
        }
        catch
        {
            await RollbackAsync("ROLLBACK TO SAVEPOINT " + name).ConfigureAwait(false);
            await RollbackAsync("RELEASE SAVEPOINT " + name).ConfigureAwait(false);

            throw;
        }
        finally
        {
            state.Depth--;
        }
    }

    /// <summary>
    /// Undo what the work did. A failure here must not hide the failure that got us here, and SQLite may already have undone the
    /// transaction by itself (a disk error does), so it is not an error for there to be nothing to undo.
    /// </summary>
    private async Task RollbackAsync(string statement)
    {
        try
        {
            await ExecuteAsync(statement, null, CancellationToken.None).ConfigureAwait(false);
        }
        catch (SqliteException)
        {
        }
    }

    private async Task<WriteResult> ApplyAsync(WriteBatch batch, CancellationToken cancellationToken)
    {
        var result = new WriteResult();

        foreach (var operation in batch.Operations)
        {
            cancellationToken.ThrowIfCancellationRequested();

            var table = manifest.Table(operation.Entity);

            switch (operation)
            {
                case Insert insert:
                    result.Assign(insert.PendingId, await InsertAsync(table, insert, cancellationToken).ConfigureAwait(false));
                    break;
                case Update update:
                    await UpdateAsync(table, update, cancellationToken).ConfigureAwait(false);
                    break;
                case Delete delete:
                    await ExecuteAsync(
                        $"DELETE FROM {SqliteNames.Quote(table.Name)} WHERE \"id\" = @p0",
                        new Dictionary<string, object?> { ["p0"] = delete.Id.Raw() },
                        cancellationToken).ConfigureAwait(false);
                    break;
                case Link link:
                    await LinkAsync(link, cancellationToken).ConfigureAwait(false);
                    break;
                case Unlink unlink:
                    await UnlinkAsync(unlink, cancellationToken).ConfigureAwait(false);
                    break;
                default:
                    throw new InvalidOperationException($"Unsupported write operation {operation.GetType().Name}.");
            }
        }

        return result;
    }

    /// <summary>
    /// Attach one row to another.
    /// </summary>
    /// <remarks>
    /// How that happens depends on where the edge lives, which is exactly why <see cref="Link" /> says nothing about columns: a
    /// foreign key on either side and a join table are three different statements for the same intent.
    /// </remarks>
    private async Task LinkAsync(Link operation, CancellationToken cancellationToken)
    {
        var placement = manifest.Placement(operation.Entity, operation.Edge);
        var from = Raw(operation.From);
        var to = Raw(operation.To);

        if (placement.UsesJoinTable)
        {
            // INSERT OR IGNORE, because linking twice is not an error: the unique index on the pair already says a link exists
            // at most once.
            await ExecuteAsync(
                $"INSERT OR IGNORE INTO {SqliteNames.Quote(placement.Table)} ({SqliteNames.Quote(placement.LocalColumn)}, {SqliteNames.Quote(placement.TargetColumn!)}) VALUES (@p0, @p1)",
                new Dictionary<string, object?> { ["p0"] = from, ["p1"] = to },
                cancellationToken).ConfigureAwait(false);

            return;
        }

        // The key column lives on one side or the other; whichever it is, the row carrying it is updated to point at the other.
        var (row, value) = placement.KeyIsLocal ? (from, to) : (to, from);

        await ExecuteAsync(
            $"UPDATE {SqliteNames.Quote(placement.Table)} SET {SqliteNames.Quote(placement.LocalColumn)} = @p0 WHERE \"id\" = @p1",
            new Dictionary<string, object?> { ["p0"] = value, ["p1"] = row },
            cancellationToken).ConfigureAwait(false);
    }

    /// <summary>Detach one row from another, or clear the edge entirely when no target is named.</summary>
    private async Task UnlinkAsync(Unlink operation, CancellationToken cancellationToken)
    {
        var placement = manifest.Placement(operation.Entity, operation.Edge);
        var table = SqliteNames.Quote(placement.Table);
        var local = SqliteNames.Quote(placement.LocalColumn);
        var bindings = new Dictionary<string, object?> { ["p0"] = Raw(operation.From) };
        var to = operation.To is null ? null : Raw(operation.To);

        if (to is not null)
        {
            bindings["p1"] = to;
        }

        string sql;

        if (placement.UsesJoinTable)
        {
            sql = $"DELETE FROM {table} WHERE {local} = @p0"
                + (to is null ? string.Empty : $" AND {SqliteNames.Quote(placement.TargetColumn!)} = @p1");
        }
        else if (placement.KeyIsLocal)
        {
            sql = $"UPDATE {table} SET {local} = NULL WHERE \"id\" = @p0"
                + (to is null ? string.Empty : $" AND {local} = @p1");
        }
        else
        {
            sql = $"UPDATE {table} SET {local} = NULL WHERE {local} = @p0"
                + (to is null ? string.Empty : " AND \"id\" = @p1");
        }

        await ExecuteAsync(sql, bindings, cancellationToken).ConfigureAwait(false);
    }

    private async Task<EntityId> InsertAsync(SqliteTable table, Insert operation, CancellationToken cancellationToken)
    {
        var bindings = new Dictionary<string, object?>();
        var columns = new List<string>();

        foreach (var (field, value) in operation.Values)
        {
            columns.Add(SqliteNames.Quote(manifest.Column(operation.Entity, field)));
            bindings["p" + bindings.Count.ToString(CultureInfo.InvariantCulture)] = Bind(field, value);
        }

        var sql = columns.Count == 0
            ? $"INSERT INTO {SqliteNames.Quote(table.Name)} DEFAULT VALUES"
            : $"INSERT INTO {SqliteNames.Quote(table.Name)} ({string.Join(", ", columns)}) VALUES ({string.Join(", ", bindings.Keys.Select(name => "@" + name))})";

        await ExecuteAsync(sql, bindings, cancellationToken).ConfigureAwait(false);

        var inserted = await SelectAsync("SELECT last_insert_rowid()", null, cancellationToken).ConfigureAwait(false);

        return EntityId.Of(Convert.ToInt64(inserted[0].Values.First(), CultureInfo.InvariantCulture));
    }

    private async Task UpdateAsync(SqliteTable table, Update operation, CancellationToken cancellationToken)
    {
        if (operation.Values.Count == 0)
        {
            return;
        }

        var bindings = new Dictionary<string, object?>();
        var assignments = new List<string>();

        foreach (var (field, value) in operation.Values)
        {
            var name = "p" + bindings.Count.ToString(CultureInfo.InvariantCulture);

            assignments.Add($"{SqliteNames.Quote(manifest.Column(operation.Entity, field))} = @{name}");
            bindings[name] = Bind(field, value);
        }

        bindings["id"] = operation.Id.Raw();

        await ExecuteAsync(
            $"UPDATE {SqliteNames.Quote(table.Name)} SET {string.Join(", ", assignments)} WHERE \"id\" = @id",
            bindings,
            cancellationToken).ConfigureAwait(false);
    }

    /// <summary>An id as the database holds it: the number or the text, not the wrapper.</summary>
    private static object Raw(IIdentifier identifier) =>
        identifier is EntityId id ? id.Raw() : identifier.ToString()!;

    /// <summary>
    /// A value as SQLite stores it. Booleans are the integers 1 and 0, as PDO binds them; dates and JSON arrive already encoded
    /// as text by the runtime.
    /// </summary>
    private static object Bind(string field, object? value) => value switch
    {
        null => DBNull.Value,
        string or long or double => value,
        bool flag => flag ? 1L : 0L,
        int or short or sbyte or byte or ushort or uint => Convert.ToInt64(value, CultureInfo.InvariantCulture),
        float number => (double)number,
        _ => throw new ArgumentException($"Field {field} holds a {value.GetType().Name}; a stored value must be a string, a number, a bool or null."),
    };

    private Record ToRecord(string entity, Dictionary<string, object?> row)
    {
        var id = row.GetValueOrDefault("id") switch
        {
            long number => EntityId.Of(number),
            string text => EntityId.Of(text),
            _ => throw new InvalidOperationException($"A {entity} row came back without an id."),
        };

        // Columns become fields here, so nothing above the adaptor ever sees a snake_case name or has to know how this backend
        // spells things.
        var values = new Dictionary<string, object?>();

        foreach (var (column, value) in row)
        {
            if (column != "id")
            {
                values[manifest.Field(entity, column)] = value;
            }
        }

        return new Record(entity, id, values);
    }

    /// <summary>Runs <paramref name="body" /> with the connection to itself: inside a transaction it already is.</summary>
    private async Task<T> WithConnection<T>(Func<Task<T>> body, CancellationToken cancellationToken)
    {
        if (ambient.Value is not null)
        {
            return await body().ConfigureAwait(false);
        }

        await gate.WaitAsync(cancellationToken).ConfigureAwait(false);

        try
        {
            await EnsureOpenAsync(cancellationToken).ConfigureAwait(false);

            return await body().ConfigureAwait(false);
        }
        finally
        {
            gate.Release();
        }
    }

    private async Task EnsureOpenAsync(CancellationToken cancellationToken)
    {
        if (connection.State != System.Data.ConnectionState.Open)
        {
            await connection.OpenAsync(cancellationToken).ConfigureAwait(false);
            configured = false;
        }

        if (configured)
        {
            return;
        }

        foreach (var pragma in Pragmas)
        {
            await ExecuteAsync(pragma, null, cancellationToken).ConfigureAwait(false);
        }

        configured = true;
    }

    private async Task<int> ExecuteAsync(string sql, IReadOnlyDictionary<string, object?>? bindings, CancellationToken cancellationToken)
    {
        await using var command = Command(sql, bindings);

        return await command.ExecuteNonQueryAsync(cancellationToken).ConfigureAwait(false);
    }

    private async Task<List<Dictionary<string, object?>>> SelectAsync(
        string sql,
        IReadOnlyDictionary<string, object?>? bindings,
        CancellationToken cancellationToken)
    {
        await using var command = Command(sql, bindings);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken).ConfigureAwait(false);
        var rows = new List<Dictionary<string, object?>>();

        while (await reader.ReadAsync(cancellationToken).ConfigureAwait(false))
        {
            var row = new Dictionary<string, object?>(reader.FieldCount);

            for (var index = 0; index < reader.FieldCount; index++)
            {
                row[reader.GetName(index)] = reader.IsDBNull(index) ? null : reader.GetValue(index);
            }

            rows.Add(row);
        }

        return rows;
    }

    private SqliteCommand Command(string sql, IReadOnlyDictionary<string, object?>? bindings)
    {
        var command = connection.CreateCommand();

        command.CommandText = sql;

        foreach (var (name, value) in bindings ?? new Dictionary<string, object?>())
        {
            command.Parameters.AddWithValue("@" + name, value ?? DBNull.Value);
        }

        return command;
    }

    private sealed class TransactionState
    {
        public int Depth { get; set; }
    }
}
