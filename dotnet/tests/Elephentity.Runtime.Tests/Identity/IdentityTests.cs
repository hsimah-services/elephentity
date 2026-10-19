using Elephentity.Runtime.Identity;

namespace Elephentity.Runtime.Tests.Identity;

public sealed class IdentityTests
{
    [Fact]
    public void A_persisted_id_compares_by_value()
    {
        Assert.True(EntityId.Of(7).Equals(EntityId.Of(7)));
        Assert.False(EntityId.Of(7).Equals(EntityId.Of(8)));
    }

    [Fact]
    public void A_persisted_id_renders_as_a_string()
    {
        // Opaque above the storage layer: consumers see a string, never an integer.
        Assert.Equal("7", EntityId.Of(7).ToString());
        Assert.Equal("7", EntityId.Of("7").ToString());
    }

    [Fact]
    public void An_id_cannot_be_empty()
    {
        Assert.Throws<ArgumentException>(() => EntityId.Of("  "));
    }

    [Fact]
    public void An_auto_increment_id_cannot_be_zero_or_negative()
    {
        Assert.Throws<ArgumentException>(() => EntityId.Of(0));
        Assert.Throws<ArgumentException>(() => EntityId.Of(-3));
    }

    [Fact]
    public void An_integer_id_and_the_string_that_spells_it_are_different_ids()
    {
        // PHP's === between int 7 and string "7" is false, and so is this.
        Assert.False(EntityId.Of(7).Equals(EntityId.Of("7")));
        Assert.False(EntityId.Of("7").Equals(EntityId.Of(7)));
        Assert.NotEqual(EntityId.Of(7), EntityId.Of("7"));
        Assert.Equal(EntityId.Of("a"), EntityId.Of("a"));
    }

    [Fact]
    public void A_raw_id_keeps_the_form_it_was_stored_in()
    {
        Assert.IsType<long>(EntityId.Of(7).Raw());
        Assert.IsType<string>(EntityId.Of("7").Raw());
        Assert.Equal(7L, EntityId.Of(7).Raw());
    }

    [Fact]
    public void An_id_is_persisted()
    {
        Assert.True(EntityId.Of(1).IsPersisted);
    }

    [Fact]
    public void A_pending_id_is_only_equal_to_itself()
    {
        var first = new PendingId("Post");
        var second = new PendingId("Post");

        Assert.True(first.Equals(first));
        Assert.False(first.Equals(second));
        Assert.False(first.IsPersisted);
    }

    [Fact]
    public void A_pending_id_names_its_entity()
    {
        Assert.StartsWith("pending:Post#", new PendingId("Post").ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public void A_pending_id_is_never_equal_to_a_persisted_one()
    {
        Assert.False(new PendingId("Post").Equals(EntityId.Of(1)));
        Assert.False(EntityId.Of(1).Equals(new PendingId("Post")));
    }
}
