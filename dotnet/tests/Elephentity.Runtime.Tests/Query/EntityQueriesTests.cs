using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;

namespace Elephentity.Runtime.Tests.Query;

public sealed class EntityQueriesTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    private sealed record Post(string Title);

    private sealed class Fixed(params object[] items) : IEntityQuery<object>
    {
        public int Reads { get; private set; }

        public ValueTask<long> CountAsync(CancellationToken cancellationToken = default) => ValueTask.FromResult((long)items.Length);

        public ValueTask<Page<object>> PageAsync(int limit, ICursor? after = null, CancellationToken cancellationToken = default)
        {
            Reads++;

            return ValueTask.FromResult(new Page<object>([.. items.Take(limit)], items.Length > limit ? new OffsetCursor(limit) : null));
        }

        public ValueTask<IReadOnlyList<object>> AllAsync(CancellationToken cancellationToken = default)
        {
            Reads++;

            return ValueTask.FromResult<IReadOnlyList<object>>(items);
        }

        public ValueTask<object?> FirstAsync(CancellationToken cancellationToken = default)
        {
            Reads++;

            return ValueTask.FromResult(items.FirstOrDefault());
        }

        public ValueTask<bool> ExistsAsync(CancellationToken cancellationToken = default) => ValueTask.FromResult(items.Length > 0);
    }

    [Fact]
    public async Task ATypedViewPassesEverythingThrough()
    {
        var query = new Fixed(new Post("a"), new Post("b"), new Post("c")).Cast<Post>();

        Assert.Equal(3, await query.CountAsync(Token));
        Assert.True(await query.ExistsAsync(Token));
        Assert.Equal(["a", "b", "c"], (await query.AllAsync(Token)).Select(post => post.Title));
        Assert.Equal("a", (await query.FirstAsync(Token))!.Title);

        var page = await query.PageAsync(2, null, Token);

        Assert.Equal(["a", "b"], page.Items.Select(post => post.Title));
        Assert.True(page.HasMore);
        Assert.Equal(new OffsetCursor(2), page.Next);
    }

    [Fact]
    public async Task AnEmptySetHasNoFirstEntity()
    {
        var query = new Fixed().Cast<Post>();

        Assert.Null(await query.FirstAsync(Token));
        Assert.False(await query.ExistsAsync(Token));
    }

    [Fact]
    public async Task NothingIsReadUntilTheViewIsAskedAndAWrongTypeFailsWhenRead()
    {
        var inner = new Fixed("not a post");
        var query = inner.Cast<Post>();

        Assert.Equal(0, inner.Reads);
        await Assert.ThrowsAsync<InvalidCastException>(async () => await query.AllAsync(Token));
    }

    [Fact]
    public void ANullQueryIsRefused() => Assert.Throws<ArgumentNullException>(() => ((IEntityQuery<object>)null!).Cast<Post>());

    [Fact]
    public async Task AnObjectViewOfATypedQueryPagesCountsAndReadsTheSameRows()
    {
        var typed = new Fixed(new Post("a"), new Post("b")).Cast<Post>();
        var objects = typed.AsObjects();

        Assert.Equal(2, await objects.CountAsync(Token));
        Assert.Equal(new Post("a"), (await objects.PageAsync(1, null, Token)).Items.Single());
        Assert.True((await objects.PageAsync(1, null, Token)).HasMore);
        Assert.Equal(2, (await objects.AllAsync(Token)).Count);
        Assert.Equal(new Post("a"), await objects.FirstAsync(Token));
        Assert.True(await objects.ExistsAsync(Token));
    }

    [Fact]
    public void AnObjectQueryIsNotWrappedAgain()
    {
        var untyped = new Fixed(new Post("a"));

        Assert.Same(untyped, untyped.AsObjects());
    }
}
