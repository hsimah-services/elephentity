using System.Text;

namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// One statement of the initial schema, with its table and index names left open so a deployment prefix can be put in front
/// of them.
/// </summary>
/// <param name="Parts">The statement, in order: literal SQL, and the names a prefix applies to.</param>
public sealed record SqliteStatement(IReadOnlyList<SqliteStatementPart> Parts)
{
    /// <summary>The SQL, with <paramref name="prefix" /> in front of every table and index name, each quoted.</summary>
    /// <param name="prefix">The deployment prefix; empty for none.</param>
    /// <returns>The statement.</returns>
    /// <exception cref="ArgumentException">A name, with its prefix, is not a valid identifier.</exception>
    public string Render(string prefix)
    {
        var sql = new StringBuilder();

        foreach (var part in Parts)
        {
            sql.Append(part.IsName ? SqliteNames.Quote(prefix + part.Text) : part.Text);
        }

        return sql.ToString();
    }
}

/// <summary>A piece of a <see cref="SqliteStatement" />.</summary>
/// <param name="Text">The literal SQL, or the name.</param>
/// <param name="IsName">Whether it is a table or index name, which takes the prefix and the quotes.</param>
public readonly record struct SqliteStatementPart(string Text, bool IsName)
{
    /// <summary>Literal SQL, written as it is.</summary>
    /// <param name="text">The SQL.</param>
    /// <returns>The part.</returns>
    public static SqliteStatementPart Sql(string text) => new(text, false);

    /// <summary>A table or index name: prefixed and quoted when the statement is rendered.</summary>
    /// <param name="name">The name, without a prefix.</param>
    /// <returns>The part.</returns>
    public static SqliteStatementPart Name(string name) => new(name, true);
}
