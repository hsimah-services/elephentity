using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Runtime.Tests.Storage;

public sealed class StorageContractTests
{
    [Fact]
    public void Paging_adds_a_unique_final_order_without_changing_explicit_id_direction()
    {
        var criteria = Criteria.For("Post").OrderBy(Order.Descending("rank"));
        var page = criteria.Take(3);

        Assert.Single(criteria.Orders);
        Assert.Equal(["rank", "id"], page.Orders.Select(order => order.Field));
        Assert.Equal(Direction.Ascending, page.Orders[1].Direction);

        var explicitId = Criteria.For("Post").OrderBy(Order.Descending("id")).Take(3);

        Assert.Single(explicitId.Orders);
        Assert.Equal(Direction.Descending, explicitId.Orders[0].Direction);
        Assert.Equal("id", Criteria.For("Post").Take(3).Orders[0].Field);
    }

    [Fact]
    public void Criteria_builders_do_not_mutate_the_original()
    {
        var baseline = Criteria.For("Post");

        var narrowed = baseline
            .Where(Filter.Equal("status", "published"))
            .OrderBy(Order.Descending("createdAt"))
            .Take(20);

        Assert.Empty(baseline.Filters);
        Assert.Null(baseline.Limit);
        Assert.Single(narrowed.Filters);
        Assert.Equal(20, narrowed.Limit);
        Assert.Equal(Direction.Descending, narrowed.Orders[0].Direction);
    }

    [Fact]
    public void Unbounded_criteria_drop_paging_but_keep_the_rest()
    {
        var paged = Criteria.For("Post")
            .Where(Filter.IsNotNull("title"))
            .Take(10, Cursor.Of("abc"));

        var unbounded = paged.Unbounded();

        Assert.Null(unbounded.Limit);
        Assert.Null(unbounded.After);
        Assert.Single(unbounded.Filters);
        Assert.Equal(paged.Orders, unbounded.Orders);
    }

    [Fact]
    public void Criteria_collect_edge_links_alongside_filters()
    {
        var link = EdgeFilter.Along("Post", "author", EntityId.Of(1));

        var criteria = Criteria.For("Post").LinkedTo(link).Where(Filter.Equal("status", "live"));

        Assert.Same(link, Assert.Single(criteria.Links));
        Assert.Single(criteria.Filters);
    }

    [Fact]
    public void A_valueless_comparison_carries_no_value()
    {
        Assert.Null(Filter.IsNull("deletedAt").Value);
        Assert.Null(Filter.IsNotNull("deletedAt").Value);
    }

    [Fact]
    public void A_filter_value_must_be_a_scalar_and_other_integers_widen_to_long()
    {
        Assert.Equal(5L, Filter.Equal("n", 5).Value);
        Assert.Equal(5L, Filter.Equal("n", (short)5).Value);
        Assert.Equal(1.5, Filter.GreaterThan("n", 1.5f).Value);
        Assert.Equal(true, Filter.Equal("flag", true).Value);
        Assert.Equal(Comparison.NotIn, Filter.NotIn("n", [1, 2]).Comparison);
        Assert.Equal([1L, 2L], Assert.IsAssignableFrom<IReadOnlyList<object>>(Filter.In("n", [1, 2]).Value));

        Assert.Throws<ArgumentException>(() => Filter.Equal("when", DateTimeOffset.UnixEpoch));
        Assert.Throws<ArgumentException>(() => Filter.In("n", [new object()]));
    }

    [Fact]
    public void Every_comparison_round_trips_through_its_wire_name()
    {
        foreach (var comparison in Enum.GetValues<Comparison>())
        {
            Assert.Equal(comparison, ComparisonExtensions.FromWire(comparison.ToWire()));
        }

        Assert.Equal("startsWith", Comparison.StartsWith.ToWire());
        Assert.Equal("asc", Direction.Ascending.ToWire());
        Assert.Equal(Direction.Descending, DirectionExtensions.FromWire("desc"));
        Assert.Equal("cascade", DeletionPolicy.Cascade.ToWire());
        Assert.Equal(DeletionPolicy.Nullify, DeletionPolicyExtensions.FromWire("nullify"));
        Assert.Throws<ArgumentException>(() => ComparisonExtensions.FromWire("nope"));
    }

    [Fact]
    public void A_cursor_cannot_be_empty()
    {
        Assert.Throws<ArgumentException>(() => Cursor.Of(string.Empty));
    }

    [Fact]
    public void A_page_without_a_cursor_has_no_more()
    {
        var page = Page.Empty<string>();

        Assert.True(page.IsEmpty);
        Assert.False(page.HasMore);

        var more = new Page<string>(["a"], Cursor.Of("abc"));

        Assert.True(more.HasMore);
        Assert.Equal("abc", more.Next?.Token);
    }

    [Fact]
    public void The_page_size_cap_is_a_thousand_rows()
    {
        Assert.Equal(1000, Page<string>.MaxLimit);
    }

    [Fact]
    public void A_record_reads_missing_fields_as_null()
    {
        var record = new Record("Post", EntityId.Of(1), new Dictionary<string, object?> { ["title"] = "Hello" });

        Assert.Equal("Hello", record.Value("title"));
        Assert.Null(record.Value("subtitle"));
        Assert.False(record.Has("subtitle"));
        Assert.True(record.Has("title"));
    }

    [Fact]
    public void A_write_result_resolves_pending_ids_to_real_ones()
    {
        var pending = new PendingId("Post");
        var result = new WriteResult();

        Assert.False(result.WasAssigned(pending));

        result.Assign(pending, EntityId.Of(42));

        Assert.True(result.WasAssigned(pending));
        Assert.True(EntityId.Of(42).Equals(result.IdFor(pending)));
    }

    [Fact]
    public void Asking_for_an_unassigned_id_fails()
    {
        Assert.Throws<KeyNotFoundException>(() => new WriteResult().IdFor(new PendingId("Post")));
    }

    [Fact]
    public void A_batch_preserves_the_order_it_was_given()
    {
        // Order is the unit of work's responsibility: with server-generated ids a Post must be
        // inserted before the Comments that reference it.
        var post = new Insert("Post", new PendingId("Post"), new Dictionary<string, object?> { ["title"] = "Hello" });
        var comment = new Insert("Comment", new PendingId("Comment"), new Dictionary<string, object?> { ["body"] = "Hi" });

        var batch = new WriteBatch(post, comment);

        Assert.Equal(2, batch.Count);
        Assert.Equal("Post", batch.Operations[0].Entity);
        Assert.Equal("Comment", batch.Operations[1].Entity);
        Assert.False(batch.IsEmpty);
        Assert.True(new WriteBatch().IsEmpty);
    }

    [Fact]
    public void A_write_operation_targets_the_row_it_changes()
    {
        var pending = new PendingId("Post");
        var persisted = EntityId.Of(3);

        Assert.Same(pending, new Insert("Post", pending, new Dictionary<string, object?>()).Target);
        Assert.Same(persisted, new Update("Post", persisted, new Dictionary<string, object?>()).Target);
        Assert.Same(persisted, new Delete("Post", persisted).Target);
        Assert.Same(persisted, new Link("Post", "comments", persisted, pending).Target);
        Assert.Same(persisted, new Unlink("Post", "comments", persisted).Target);
        Assert.Null(new Unlink("Post", "comments", persisted).To);
    }

    [Fact]
    public void An_edge_filter_needs_a_parent_and_says_when_it_names_several()
    {
        Assert.Throws<ArgumentException>(() => EdgeFilter.Along("Post", "comments"));

        var one = EdgeFilter.Along("Post", "comments", EntityId.Of(1));
        var many = EdgeFilter.Back("Post", "comments", EntityId.Of(1), EntityId.Of(2));

        Assert.False(one.NeedsParentColumn());
        Assert.True(many.NeedsParentColumn());
        Assert.False(one.Reversed);
        Assert.True(many.Reversed);
        Assert.Equal("__parent", EdgeFilter.ParentColumn);
    }

    [Fact]
    public void Only_many_to_many_needs_a_join_table_and_the_key_is_local_on_the_to_one_side()
    {
        Assert.True(RelationKind.ManyToMany.NeedsJoinTable());
        Assert.False(RelationKind.OneToMany.NeedsJoinTable());
        Assert.True(RelationKind.OneToOne.KeyIsLocal());
        Assert.True(RelationKind.ManyToOne.KeyIsLocal());
        Assert.False(RelationKind.OneToMany.KeyIsLocal());
        Assert.False(RelationKind.ManyToMany.KeyIsLocal());
    }
}
