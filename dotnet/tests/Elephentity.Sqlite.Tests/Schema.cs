using Elephentity.Runtime.Storage;
using Elephentity.Sqlite.Manifest;
using Microsoft.Data.Sqlite;

namespace Elephentity.Sqlite.Tests;

/// <summary>
/// Manifests the tests share, with the schema statements written out by hand, in the shape <c>eleph-gen-sqlite</c> writes them.
/// </summary>
internal static class Schema
{
    /// <summary>A column, and the clauses the installer adds to it.</summary>
    public sealed record Col(string Name, string Type, bool Nullable = false, string? References = null, string? Check = null);

    /// <summary>
    /// Post.author is many-to-one (<c>post.author_id</c>), Post.comments is one-to-many (<c>comment.post_id</c>), Post.tags is
    /// many-to-many (<c>post_tags</c>) and Author.profile is one-to-one (<c>author.profile_id</c>, unique).
    /// </summary>
    public static SqliteStorageManifest Blog()
    {
        var post = Table("post", [new("title", "TEXT"), new("slug", "TEXT"), new("rank", "INTEGER", true), new("views", "INTEGER", true), new("pinned", "INTEGER", true, Check: "\"pinned\" IN (0, 1)"), new("author_id", "INTEGER", true, "author")], ("slug", true), ("rank", false));
        var author = Table("author", [new("name", "TEXT"), new("profile_id", "INTEGER", true, "profile")], ("profile_id", true));
        var comment = Table("comment", [new("body", "TEXT"), new("kind", "TEXT", true), new("post_id", "INTEGER", true, "post")], ("post_id", false));
        var tag = Table("tag", [new("label", "TEXT")]);
        var profile = Table("profile", [new("bio", "TEXT", true)]);
        var postTags = Join("post_tags", "post_id", "post", "tag_id", "tag");

        return new SqliteStorageManifest(
            new Dictionary<string, SqliteTable> { ["Post"] = post.Table, ["Author"] = author.Table, ["Comment"] = comment.Table, ["Tag"] = tag.Table, ["Profile"] = profile.Table },
            new Dictionary<string, SqliteEdgePlacement>
            {
                ["Post.author"] = new("Post", "author", "Author", RelationKind.ManyToOne, "post", "author_id", null, "author"),
                ["Post.comments"] = new("Post", "comments", "Comment", RelationKind.OneToMany, "comment", "post_id", null, "comment"),
                ["Post.tags"] = new("Post", "tags", "Tag", RelationKind.ManyToMany, "post_tags", "post_id", "tag_id", "tag"),
                ["Author.profile"] = new("Author", "profile", "Profile", RelationKind.OneToOne, "author", "profile_id", null, "profile"),
            },
            new Dictionary<string, IReadOnlyDictionary<string, string>>
            {
                ["Post"] = new Dictionary<string, string> { ["title"] = "title", ["slug"] = "slug", ["rank"] = "rank", ["views"] = "views", ["pinned"] = "pinned" },
                ["Author"] = new Dictionary<string, string> { ["name"] = "name" },
                ["Comment"] = new Dictionary<string, string> { ["body"] = "body", ["type"] = "kind" },
                ["Tag"] = new Dictionary<string, string> { ["label"] = "label" },
                ["Profile"] = new Dictionary<string, string> { ["bio"] = "bio" },
            },
            new Dictionary<string, SqliteTable> { ["post_tags"] = postTags.Table },
            [.. new[] { profile, author, post, comment, tag, postTags }.SelectMany(built => built.Statements)]);
    }

    /// <summary>The entities and the many-to-many edge the conformance cases write to.</summary>
    public static SqliteStorageManifest Conformance()
    {
        var entity = Table("conformance_entity", [new("name", "TEXT", true), new("rank", "INTEGER", true)]);
        var related = Table("conformance_related", [new("name", "TEXT", true)]);
        var join = Join("conformance_entity_related", "conformance_entity_id", "conformance_entity", "conformance_related_id", "conformance_related");

        return new SqliteStorageManifest(
            new Dictionary<string, SqliteTable> { ["ConformanceEntity"] = entity.Table, ["ConformanceRelated"] = related.Table },
            new Dictionary<string, SqliteEdgePlacement>
            {
                ["ConformanceEntity.related"] = new("ConformanceEntity", "related", "ConformanceRelated", RelationKind.ManyToMany, "conformance_entity_related", "conformance_entity_id", "conformance_related_id", "conformance_related"),
            },
            new Dictionary<string, IReadOnlyDictionary<string, string>>
            {
                ["ConformanceEntity"] = new Dictionary<string, string> { ["name"] = "name", ["rank"] = "rank" },
                ["ConformanceRelated"] = new Dictionary<string, string> { ["name"] = "name" },
            },
            new Dictionary<string, SqliteTable> { ["conformance_entity_related"] = join.Table },
            [.. new[] { entity, related, join }.SelectMany(built => built.Statements)]);
    }

    /// <summary>A connection to a database of its own, with <paramref name="manifest" />'s schema installed.</summary>
    public static async Task<SqliteConnection> InstalledAsync(SqliteStorageManifest manifest, string prefix = "")
    {
        var connection = new SqliteConnection("Data Source=:memory:");

        await connection.OpenAsync();
        await SqliteInstaller.InstallAsync(connection, manifest, prefix);

        return connection;
    }

    /// <summary>Runs one statement for its effect.</summary>
    public static async Task<long> ScalarAsync(SqliteConnection connection, string sql)
    {
        await using var command = connection.CreateCommand();

        command.CommandText = sql;

        return Convert.ToInt64(await command.ExecuteScalarAsync(), System.Globalization.CultureInfo.InvariantCulture);
    }

    private static (SqliteTable Table, List<SqliteStatement> Statements) Table(string name, Col[] columns, params (string Column, bool Unique)[] indexed)
    {
        var all = new List<SqliteColumn> { new("id", "INTEGER", false, true) };

        all.AddRange(columns.Select(column => new SqliteColumn(column.Name, column.Type, column.Nullable || column.References is not null)));

        var indexes = indexed.Select(index => new SqliteIndex($"{name}_{index.Column}_{(index.Unique ? "uniq" : "idx")}", [index.Column], index.Unique)).ToList();
        var statements = new List<SqliteStatement> { Create(name, [new Col("id", "INTEGER"), .. columns], identity: true) };

        statements.AddRange(indexes.Select(index => Index(name, index)));

        return (new SqliteTable(name, all, indexes), statements);
    }

    private static (SqliteTable Table, List<SqliteStatement> Statements) Join(string name, string left, string leftTable, string right, string rightTable)
    {
        var pair = new SqliteIndex($"{name}_pair_uniq", [left, right], true);
        var other = new SqliteIndex($"{name}_{right}_idx", [right]);

        return (
            new SqliteTable(name, [new(left, "INTEGER"), new(right, "INTEGER")], [pair, other], string.Empty),
            [Create(name, [new Col(left, "INTEGER", References: leftTable), new Col(right, "INTEGER", References: rightTable)], identity: false), Index(name, pair), Index(name, other)]);
    }

    private static SqliteStatement Create(string name, Col[] columns, bool identity)
    {
        var parts = new List<SqliteStatementPart> { SqliteStatementPart.Sql("CREATE TABLE "), SqliteStatementPart.Name(name), SqliteStatementPart.Sql(" (\n    ") };

        for (var index = 0; index < columns.Length; index++)
        {
            var column = columns[index];
            var sql = $"\"{column.Name}\" {column.Type}";

            sql += identity && index == 0 ? " PRIMARY KEY AUTOINCREMENT" : column.Nullable || column.References is not null ? string.Empty : " NOT NULL";
            sql += column.Check is null ? string.Empty : $" CHECK ({column.Check})";
            sql = (index > 0 ? ",\n    " : string.Empty) + sql;

            parts.Add(SqliteStatementPart.Sql(sql));

            if (column.References is not null)
            {
                parts.Add(SqliteStatementPart.Sql(" REFERENCES "));
                parts.Add(SqliteStatementPart.Name(column.References));
                parts.Add(SqliteStatementPart.Sql("(\"id\") ON DELETE RESTRICT"));
            }
        }

        parts.Add(SqliteStatementPart.Sql("\n)"));

        return new SqliteStatement(parts);
    }

    private static SqliteStatement Index(string table, SqliteIndex index) =>
        new([
            SqliteStatementPart.Sql(index.Unique ? "CREATE UNIQUE INDEX " : "CREATE INDEX "),
            SqliteStatementPart.Name(index.Name),
            SqliteStatementPart.Sql(" ON "),
            SqliteStatementPart.Name(table),
            SqliteStatementPart.Sql(" (" + string.Join(", ", index.Columns.Select(column => $"\"{column}\"")) + ")"),
        ]);
}
