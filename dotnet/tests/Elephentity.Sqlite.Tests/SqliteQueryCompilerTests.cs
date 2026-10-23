using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Sqlite.Query;

namespace Elephentity.Sqlite.Tests;

public sealed class SqliteQueryCompilerTests
{
    private readonly Elephentity.Sqlite.Manifest.SqliteStorageManifest manifest = Schema.Blog();

    private SqliteQuery Select(Criteria criteria) =>
        new SqliteQueryCompiler(manifest).Select(manifest.Table(criteria.Entity), criteria);

    [Fact]
    public void AnUnboundedQueryIsOrderedByIdAndValuesAreParameters()
    {
        var query = Select(Criteria.For("Post").Where(Filter.Equal("title", "x")).Where(Filter.GreaterThan("rank", 2)));

        Assert.Equal(
            "SELECT \"post\".* FROM \"post\" WHERE \"post\".\"title\" = @p0 AND \"post\".\"rank\" > @p1 ORDER BY \"post\".\"id\" ASC",
            query.Sql);
        Assert.Equal<object?>(["x", 2L], query.Bindings.Values);
    }

    [Fact]
    public void APageAsksForOneRowMoreThanItsSizeAndSkipsByOffset()
    {
        Assert.EndsWith(
            "ORDER BY \"post\".\"rank\" DESC, \"post\".\"id\" ASC LIMIT 11",
            Select(Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(10)).Sql,
            StringComparison.Ordinal);
        Assert.EndsWith(
            "LIMIT 11 OFFSET 20",
            Select(Criteria.For("Post").Take(10, new OffsetCursor(20))).Sql,
            StringComparison.Ordinal);
        Assert.EndsWith("LIMIT 1001", Select(Criteria.For("Post").Take(5000)).Sql, StringComparison.Ordinal);
    }

    [Fact]
    public void AKeysetPositionBecomesAnExpandedComparisonNotAnOffset()
    {
        var query = Select(Criteria.For("Post").OrderBy(Order.Ascending("rank")).Take(5, new KeysetCursor([3L], EntityId.Of(9))));

        Assert.Contains(
            "WHERE ((\"post\".\"rank\" > @p0) OR (\"post\".\"rank\" = @p1 AND \"post\".\"id\" > @p2))",
            query.Sql,
            StringComparison.Ordinal);
        Assert.DoesNotContain("OFFSET", query.Sql, StringComparison.Ordinal);
        Assert.Equal<object?>([3L, 3L, 9L], query.Bindings.Values);
    }

    [Fact]
    public void ANullPositionIsComparedWithIsNullNotEquals()
    {
        var ascending = Select(Criteria.For("Post").OrderBy(Order.Ascending("rank")).Take(5, new KeysetCursor([null], EntityId.Of(9))));
        var descending = Select(Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(5, new KeysetCursor([null], EntityId.Of(9))));

        Assert.Contains("((\"post\".\"rank\" IS NOT NULL) OR (\"post\".\"rank\" IS NULL AND \"post\".\"id\" > @p0))", ascending.Sql, StringComparison.Ordinal);
        Assert.Contains("((1 = 0) OR (\"post\".\"rank\" IS NULL AND \"post\".\"id\" > @p0))", descending.Sql, StringComparison.Ordinal);
    }

    [Fact]
    public void AFieldIsSpelledAsItsColumnAndAnUnmappedNameIsSnakeCased()
    {
        Assert.Contains("\"comment\".\"kind\" = @p0", Select(Criteria.For("Comment").Where(Filter.Equal("type", "n"))).Sql, StringComparison.Ordinal);
        Assert.Contains("\"comment\".\"post_id\" IS NULL", Select(Criteria.For("Comment").Where(Filter.IsNull("postId"))).Sql, StringComparison.Ordinal);
    }

    [Fact]
    public void LikePatternsEscapeTheirWildcardsAndNothingLongerThanTheirValueIsMatched()
    {
        var query = Select(Criteria.For("Post").Where(Filter.Contains("title", "50%_\\")));

        Assert.Contains("LIKE @p0 ESCAPE '\\'", query.Sql, StringComparison.Ordinal);
        Assert.Equal<object?>(["%50\\%\\_\\\\%"], query.Bindings.Values);
    }

    [Fact]
    public void ALinkOnTheQueriedTableNeedsNoJoinAndAJoinTableDoes()
    {
        var local = Select(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "author", EntityId.Of(1), EntityId.Of(2))));
        var joined = Select(Criteria.For("Tag").LinkedTo(EdgeFilter.Along("Post", "tags", EntityId.Of(1), EntityId.Of(2))));
        var reversed = Select(Criteria.For("Post").LinkedTo(EdgeFilter.Back("Post", "tags", EntityId.Of(1))));

        Assert.Equal(
            "SELECT \"post\".*, \"post\".\"author_id\" AS \"__parent\" FROM \"post\" WHERE \"post\".\"author_id\" IN (@p0, @p1) ORDER BY \"post\".\"id\" ASC, \"__parent\" ASC",
            local.Sql);
        Assert.Equal(
            "SELECT \"tag\".*, \"l1\".\"post_id\" AS \"__parent\" FROM \"tag\" INNER JOIN \"post_tags\" \"l1\" ON \"l1\".\"tag_id\" = \"tag\".\"id\" WHERE \"l1\".\"post_id\" IN (@p0, @p1) ORDER BY \"tag\".\"id\" ASC, \"__parent\" ASC",
            joined.Sql);
        Assert.Equal(
            "SELECT \"post\".* FROM \"post\" INNER JOIN \"post_tags\" \"l1\" ON \"l1\".\"post_id\" = \"post\".\"id\" WHERE \"l1\".\"tag_id\" IN (@p0) ORDER BY \"post\".\"id\" ASC",
            reversed.Sql);
    }

    [Fact]
    public void FilterValuesOfEveryScalarTypeBind()
    {
        var query = Select(Criteria.For("Post").Where(Filter.Equal("pinned", true)).Where(Filter.Equal("views", 3)).Where(Filter.Equal("rank", 1.5)));

        Assert.Equal<object?>([1L, 3L, 1.5], query.Bindings.Values);
    }
}
