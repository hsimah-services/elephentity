using System.Text.RegularExpressions;

namespace Elephentity.Sqlite.Manifest;

/// <summary>
/// The rules for every SQL identifier the adaptor writes into a statement.
/// </summary>
/// <remarks>
/// Identifiers cannot be parameterised, so a name is checked against the same rule the PHP <c>Database::identifier()</c> applies
/// (a letter or underscore, then letters, digits and underscores) before it is quoted, rather than escaped. A manifest is
/// generated, but nothing stops one being written by hand.
/// </remarks>
internal static partial class SqliteNames
{
    [GeneratedRegex("^[A-Za-z_][A-Za-z0-9_]*$")]
    private static partial Regex Pattern();

    /// <summary>Whether <paramref name="name" /> may be written as an identifier.</summary>
    /// <param name="name">The name.</param>
    /// <returns><see langword="true" /> when it may.</returns>
    public static bool IsValid(string name) => Pattern().IsMatch(name);

    /// <summary>Quotes <paramref name="name" /> as an identifier.</summary>
    /// <param name="name">The name; checked first.</param>
    /// <returns>The quoted identifier.</returns>
    /// <exception cref="ArgumentException">The name is not a valid identifier.</exception>
    public static string Quote(string name)
    {
        Require(name, "identifier");

        return $"\"{name}\"";
    }

    /// <summary>Fails unless <paramref name="name" /> may be written as an identifier.</summary>
    /// <param name="name">The name.</param>
    /// <param name="what">What the name is, for the message.</param>
    /// <exception cref="ArgumentException">The name is not a valid identifier.</exception>
    public static void Require(string name, string what)
    {
        if (!IsValid(name))
        {
            throw new ArgumentException($"\"{name}\" is not a valid SQLite {what}: use letters, digits and underscores, not starting with a digit.");
        }
    }

    /// <summary>
    /// camelCase in the spec, snake_case in the database: the convention on each side. <c>postId</c> becomes
    /// <c>post_id</c>.
    /// </summary>
    /// <param name="field">The field.</param>
    /// <returns>The column name.</returns>
    public static string Snake(string field)
    {
        var builder = new System.Text.StringBuilder(field.Length + 4);

        for (var index = 0; index < field.Length; index++)
        {
            var character = field[index];

            if (index > 0 && char.IsAsciiLetterUpper(character))
            {
                builder.Append('_');
            }

            builder.Append(char.ToLowerInvariant(character));
        }

        return builder.ToString();
    }
}
