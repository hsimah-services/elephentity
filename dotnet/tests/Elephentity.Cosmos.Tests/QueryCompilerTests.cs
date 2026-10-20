using Elephentity.Cosmos.Manifest;
using Elephentity.Cosmos.Query;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos.Tests;

public sealed class QueryCompilerTests
{
    private static readonly CosmosQueryCompiler Compiler = new(Layouts.Blog());

    private static CosmosQuery Select(Criteria criteria, int? take = null, KeysetCursor? resume = null, long offset = 0, params LinkConstraint[] links) =>
        Compiler.Select(criteria, links, take, resume, offset);

    private static object? Param(CosmosQuery query, string name) => query.Parameters.Single(p => p.Key == name).Value;

    [Fact]
    public void EveryQueryIsScopedToItsEntityAndOrderedByIdAtLeast()
    {
        var query = Select(Criteria.For("Post"));

        Assert.Equal("SELECT * FROM c WHERE c.type = @p0 ORDER BY c.id ASC", query.Text);
        Assert.Equal("Post", Param(query, "@p0"));
    }

    [Fact]
    public void ValuesAreParametersAndNeverPartOfTheText()
    {
        var query = Select(Criteria.For("Post").Where(Filter.Equal("title", "x'; DROP")));

        Assert.DoesNotContain("DROP", query.Text, StringComparison.Ordinal);
        Assert.Equal("x'; DROP", Param(query, "@p1"));
        Assert.Contains("c[\"title\"] = @p1", query.Text, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("eq", "c[\"views\"] = @p1")]
    [InlineData("neq", "(NOT IS_DEFINED(c[\"views\"]) OR c[\"views\"] != @p1)")]
    [InlineData("lt", "c[\"views\"] < @p1")]
    [InlineData("lte", "c[\"views\"] <= @p1")]
    [InlineData("gt", "c[\"views\"] > @p1")]
    [InlineData("gte", "c[\"views\"] >= @p1")]
    public void ComparisonsMapToTheirOperators(string comparison, string expected)
    {
        var filter = comparison switch
        {
            "eq" => Filter.Equal("views", 3),
            "neq" => Filter.NotEqual("views", 3),
            "lt" => Filter.LessThan("views", 3),
            "lte" => Filter.LessThanOrEqual("views", 3),
            "gt" => Filter.GreaterThan("views", 3),
            _ => Filter.GreaterThanOrEqual("views", 3),
        };

        var query = Select(Criteria.For("Post").Where(filter));

        Assert.Contains(expected, query.Text, StringComparison.Ordinal);
        Assert.Equal(3L, Param(query, "@p1"));
    }

    [Fact]
    public void SetMembershipUsesArrayContainsAndAnEmptySetIsDecidedWithoutAParameter()
    {
        var present = Select(Criteria.For("Post").Where(Filter.In("title", ["a", "b"])));
        var absent = Select(Criteria.For("Post").Where(Filter.NotIn("title", ["a"])));
        var none = Select(Criteria.For("Post").Where(Filter.In("title", [])));
        var all = Select(Criteria.For("Post").Where(Filter.NotIn("title", [])));

        Assert.Contains("ARRAY_CONTAINS(@p1, c[\"title\"])", present.Text, StringComparison.Ordinal);
        Assert.Equal(["a", "b"], Assert.IsType<object[]>(Param(present, "@p1")));
        Assert.Contains("NOT ARRAY_CONTAINS(@p1, c[\"title\"])", absent.Text, StringComparison.Ordinal);
        Assert.Contains("WHERE c.type = @p0 AND 1 = 0 ORDER BY", none.Text, StringComparison.Ordinal);
        Assert.Contains("WHERE c.type = @p0 AND 1 = 1 ORDER BY", all.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void TextAndNullFiltersHaveTheirOwnForms()
    {
        var query = Select(Criteria.For("Post")
            .Where(Filter.Contains("title", "ab"))
            .Where(Filter.StartsWith("slug", "x"))
            .Where(Filter.IsNull("rank"))
            .Where(Filter.IsNotNull("views")));

        Assert.Contains("CONTAINS(c[\"title\"], @p1, false)", query.Text, StringComparison.Ordinal);
        Assert.Contains("STARTSWITH(c[\"slug\"], @p2, false)", query.Text, StringComparison.Ordinal);
        Assert.Contains("(IS_NULL(c[\"rank\"]) OR NOT IS_DEFINED(c[\"rank\"]))", query.Text, StringComparison.Ordinal);
        Assert.Contains("(IS_DEFINED(c[\"views\"]) AND NOT IS_NULL(c[\"views\"]))", query.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void AFieldIsAddressedByItsPropertyNotItsSpecName()
    {
        var query = Compiler.Select(Criteria.For("Comment").Where(Filter.Equal("type", "note")), []);

        Assert.Contains("c[\"kind\"] = @p1", query.Text, StringComparison.Ordinal);
        Assert.DoesNotContain("c[\"type\"]", query.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void AnUnknownFieldIsRefusedRatherThanQuoted()
    {
        var failure = Assert.Throws<InvalidOperationException>(() => Select(Criteria.For("Post").Where(Filter.Equal("nope\"] OR 1=1 --", "x"))));

        Assert.Contains("has no field", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void OrderTermsComeFirstAndTheIdBreaksTies()
    {
        var query = Select(Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(10), take: 11);

        Assert.Equal("SELECT TOP @p1 * FROM c WHERE c.type = @p0 ORDER BY c[\"rank\"] DESC, c.id ASC", query.Text);
        Assert.Equal(11, Param(query, "@p1"));
    }

    [Fact]
    public void AnExplicitIdOrderIsKeptAsWritten()
    {
        var query = Select(Criteria.For("Post").OrderBy(Order.Descending("id")));

        Assert.EndsWith("ORDER BY c.id DESC", query.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void ResumingIsATupleComparisonExpandedIntoAnOr()
    {
        var criteria = Criteria.For("Post").OrderBy(Order.Ascending("rank")).Take(5);
        var query = Select(criteria, take: 6, resume: new KeysetCursor([7L], EntityId.Of("abc")));

        Assert.Contains(
            "(c[\"rank\"] > @p1 OR (c[\"rank\"] = @p2 AND c.id > @p3))",
            query.Text,
            StringComparison.Ordinal);
        Assert.Equal([7L, 7L, "abc"], new[] { Param(query, "@p1"), Param(query, "@p2"), Param(query, "@p3") });
        Assert.Equal(6, Param(query, "@p4"));
    }

    [Fact]
    public void ADescendingTermResumesWithLessThanAndKeepsNullsThatSortLast()
    {
        var query = Select(
            Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(5),
            take: 6,
            resume: new KeysetCursor([7L], EntityId.Of("abc")));

        Assert.Contains("((c[\"rank\"] < @p1 OR IS_NULL(c[\"rank\"])) OR (c[\"rank\"] = @p2 AND c.id > @p3))", query.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void ResumingAfterANullSortValueIsHandledForBothDirections()
    {
        var ascending = Select(
            Criteria.For("Post").OrderBy(Order.Ascending("rank")).Take(5),
            take: 6,
            resume: new KeysetCursor([null], EntityId.Of("abc")));
        var descending = Select(
            Criteria.For("Post").OrderBy(Order.Descending("rank")).Take(5),
            take: 6,
            resume: new KeysetCursor([null], EntityId.Of("abc")));

        Assert.Contains("((IS_DEFINED(c[\"rank\"]) AND NOT IS_NULL(c[\"rank\"])) OR (IS_NULL(c[\"rank\"]) AND c.id > @p1))", ascending.Text, StringComparison.Ordinal);
        Assert.Contains("(1 = 0 OR (IS_NULL(c[\"rank\"]) AND c.id > @p1))", descending.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void ResumingWithTheWrongNumberOfSortValuesIsRefused()
    {
        var criteria = Criteria.For("Post").OrderBy(Order.Ascending("rank")).Take(5);

        Assert.Throws<ArgumentException>(() => Select(criteria, take: 6, resume: new KeysetCursor([1L, 2L], EntityId.Of("abc"))));
    }

    [Fact]
    public void AnOffsetIsHonouredButOnlyWhenThereIsNoKeysetPosition()
    {
        var query = Select(Criteria.For("Post").Take(5), take: 5, offset: 20);

        Assert.EndsWith("ORDER BY c.id ASC OFFSET @p1 LIMIT @p2", query.Text, StringComparison.Ordinal);
        Assert.Equal(20L, Param(query, "@p1"));
        Assert.Equal(5, Param(query, "@p2"));
        Assert.DoesNotContain("TOP", query.Text, StringComparison.Ordinal);
    }

    [Fact]
    public void ResolvedEdgesBecomeMembershipTests()
    {
        var query = Select(
            Criteria.For("Comment"),
            links: [new LinkConstraint("postId", ["p1", "p2"]), new LinkConstraint("id", ["c1"])]);

        Assert.Contains("ARRAY_CONTAINS(@p1, c[\"postId\"]) AND ARRAY_CONTAINS(@p2, c.id)", query.Text, StringComparison.Ordinal);
        Assert.Equal(["p1", "p2"], Assert.IsType<string[]>(Param(query, "@p1")));
    }

    [Fact]
    public void CountingIsAScalarOverTheSameConditions()
    {
        var query = Compiler.Count(Criteria.For("Post").Where(Filter.Equal("title", "x")).OrderBy(Order.Ascending("rank")).Take(3), []);

        Assert.Equal("SELECT VALUE COUNT(1) FROM c WHERE c.type = @p0 AND c[\"title\"] = @p1", query.Text);
    }
}
