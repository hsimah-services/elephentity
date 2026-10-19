using System.Text;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;

namespace Elephentity.Runtime.Tests.Storage;

public sealed class CursorTests
{
    private static string Base64(string text) => Convert.ToBase64String(Encoding.UTF8.GetBytes(text));

    [Fact]
    public void An_offset_cursor_is_base64_of_offset_and_the_number_like_php()
    {
        Assert.Equal(Base64("offset:40"), new OffsetCursor(40).Token);
        Assert.Equal("b2Zmc2V0OjQw", new OffsetCursor(40).Token);
    }

    [Fact]
    public void An_offset_cursor_round_trips_through_a_cursor_a_client_sent_back()
    {
        var sent = Cursor.Of(new OffsetCursor(125).Token);

        Assert.Equal(125, OffsetCursor.From(sent).Value);
    }

    [Fact]
    public void No_cursor_starts_at_the_beginning()
    {
        Assert.Equal(0, OffsetCursor.From(null).Value);
    }

    [Theory]
    [InlineData("not base64!")]
    [InlineData("YWJj")]
    [InlineData("b2Zmc2V0Oi0x")]
    [InlineData("b2Zmc2V0Og==")]
    [InlineData("b2Zmc2V0OjEuNQ==")]
    public void An_unreadable_cursor_restarts_the_list_rather_than_failing(string token)
    {
        Assert.Equal(0, OffsetCursor.From(Cursor.Of(token)).Value);
    }

    [Fact]
    public void An_offset_cannot_be_negative()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => new OffsetCursor(-1));
    }

    [Fact]
    public void A_keyset_cursor_round_trips_every_kind_of_sort_value_and_both_id_forms()
    {
        object?[] values = ["Hello", 12L, 1.5, true, null];

        foreach (var id in new[] { EntityId.Of(42), EntityId.Of("0192f0c1-7b3e-7d2a-8f00-aaaaaaaaaaaa") })
        {
            var sent = Cursor.Of(new KeysetCursor(values, id).Token);

            var read = KeysetCursor.From(sent);

            Assert.NotNull(read);
            Assert.Equal(values, read.SortValues);
            Assert.Equal(id, read.Id);
        }
    }

    [Fact]
    public void A_keyset_cursor_is_version_tagged_json_in_base64()
    {
        var token = new KeysetCursor(["x"], EntityId.Of(7)).Token;

        Assert.Equal("{\"v\":1,\"k\":[\"x\"],\"id\":7}", Encoding.UTF8.GetString(Convert.FromBase64String(token)));
    }

    [Fact]
    public void A_keyset_cursor_widens_integers_and_a_whole_float_comes_back_as_an_integer()
    {
        // JSON has one number type and writes 2.0 as 2. The two compare equal, which is all a
        // resumed sort needs, so the value is not tagged with its original width.
        var read = KeysetCursor.From(Cursor.Of(new KeysetCursor([(short)3, 2.0f, 2.5f], EntityId.Of(1)).Token));

        Assert.NotNull(read);
        Assert.Equal([3L, 2L, 2.5], read.SortValues);
    }

    [Theory]
    [InlineData("not base64!")]
    [InlineData("e30=")]
    [InlineData("W10=")]
    public void An_unreadable_keyset_cursor_is_not_a_keyset(string token)
    {
        Assert.Null(KeysetCursor.From(Cursor.Of(token)));
    }

    [Theory]
    [InlineData("{\"v\":2,\"k\":[],\"id\":1}")]
    [InlineData("{\"k\":[],\"id\":1}")]
    [InlineData("{\"v\":1,\"id\":1}")]
    [InlineData("{\"v\":1,\"k\":[],\"id\":0}")]
    [InlineData("{\"v\":1,\"k\":[],\"id\":\"  \"}")]
    [InlineData("{\"v\":1,\"k\":[],\"id\":[1]}")]
    [InlineData("{\"v\":1,\"k\":[{}],\"id\":1}")]
    public void A_keyset_token_of_another_version_or_shape_is_ignored_not_misread(string json)
    {
        Assert.Null(KeysetCursor.From(Cursor.Of(Base64(json))));
    }

    [Fact]
    public void An_offset_cursor_is_not_a_keyset_and_no_cursor_is_none()
    {
        Assert.Null(KeysetCursor.From(new OffsetCursor(10)));
        Assert.Null(KeysetCursor.From(null));
    }

    [Fact]
    public void A_keyset_sort_value_must_be_a_scalar()
    {
        Assert.Throws<ArgumentException>(() => new KeysetCursor([DateTimeOffset.UnixEpoch], EntityId.Of(1)));
    }
}
