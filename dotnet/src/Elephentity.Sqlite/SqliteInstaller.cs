using System.Text.RegularExpressions;
using Elephentity.Sqlite.Manifest;
using Microsoft.Data.Sqlite;

namespace Elephentity.Sqlite;

/// <summary>
/// Explicit initial installation. Ports the <c>install.php</c> closure <c>eleph-gen-sqlite</c> writes for PHP.
/// </summary>
/// <remarks>
/// An existing table causes a failure and a rollback, and nothing is changed. Evolving a populated database requires a reviewed
/// application migration: this creates a schema, and does not claim to upgrade one.
/// </remarks>
public static partial class SqliteInstaller
{
    [GeneratedRegex("^[A-Za-z_][A-Za-z0-9_]*$")]
    private static partial Regex PrefixPattern();

    /// <summary>
    /// Creates the manifest's tables and indexes in one transaction.
    /// </summary>
    /// <param name="connection">The database; opened if it is closed, and left open.</param>
    /// <param name="manifest">What to create.</param>
    /// <param name="prefix">
    /// An <em>additional</em> table prefix; use the same one with <see cref="SqliteStorageManifest.WithPrefix" />. The
    /// compiler has already applied <c>project.storage.tablePrefix</c> to the names in the manifest.
    /// </param>
    /// <param name="cancellationToken">Cancels the installation; what was done is rolled back.</param>
    /// <returns>A task that completes when the schema exists.</returns>
    /// <exception cref="ArgumentException">The prefix is not a valid identifier.</exception>
    /// <exception cref="InvalidOperationException">A transaction is already open on the connection.</exception>
    /// <exception cref="SqliteException">A statement failed, such as <c>CREATE TABLE</c> for a table that exists.</exception>
    public static async Task InstallAsync(
        SqliteConnection connection,
        SqliteStorageManifest manifest,
        string prefix = "",
        CancellationToken cancellationToken = default)
    {
        if (prefix.Length > 0 && !PrefixPattern().IsMatch(prefix))
        {
            throw new ArgumentException("Invalid SQLite table prefix.", nameof(prefix));
        }

        // Rendered first, so a name that cannot be written fails before anything is touched.
        var statements = manifest.Install.Select(statement => statement.Render(prefix)).ToList();

        if (connection.State != System.Data.ConnectionState.Open)
        {
            await connection.OpenAsync(cancellationToken).ConfigureAwait(false);
        }

        if (SQLitePCL.raw.sqlite3_get_autocommit(connection.Handle) == 0)
        {
            throw new InvalidOperationException("Run the initial installer outside an existing transaction.");
        }

        // Foreign keys are enabled before the transaction begins, because SQLite ignores the pragma inside one.
        await RunAsync(connection, "PRAGMA foreign_keys = ON", cancellationToken).ConfigureAwait(false);
        await RunAsync(connection, "BEGIN", cancellationToken).ConfigureAwait(false);

        try
        {
            foreach (var sql in statements)
            {
                await RunAsync(connection, sql, cancellationToken).ConfigureAwait(false);
            }

            await RunAsync(connection, "COMMIT", cancellationToken).ConfigureAwait(false);
        }
        catch
        {
            try
            {
                await RunAsync(connection, "ROLLBACK", CancellationToken.None).ConfigureAwait(false);
            }
            catch (SqliteException)
            {
                // SQLite had already undone it; the failure worth reporting is the one that got us here.
            }

            throw;
        }
    }

    private static async Task RunAsync(SqliteConnection connection, string sql, CancellationToken cancellationToken)
    {
        await using var command = connection.CreateCommand();

        command.CommandText = sql;

        await command.ExecuteNonQueryAsync(cancellationToken).ConfigureAwait(false);
    }
}
