using System.Globalization;
using System.Text;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;

namespace Elephentity.GraphQL.Schema;

/// <summary>
/// Shapes a lazy query into what a GraphQL connection expects.
/// </summary>
/// <remarks>
/// Ported from <c>Eleph\GraphQL\Resolver\Connections</c>. Nothing is hydrated that the caller did not ask for: one page is fetched,
/// and the total is counted only when <c>totalCount</c> is selected. Paging is forward only and uses the runtime's keyset cursor.
/// Only the last edge of a page carries a cursor, because a page knows where it ends and not where each row is; use
/// <c>pageInfo.endCursor</c>.
/// </remarks>
internal static class Connections
{
    public static async ValueTask<ConnectionResult> ResolveAsync(
        IEntityQuery<object> query,
        int? first,
        string? after,
        bool backward,
        ElephentityGraphQLOptions options,
        CancellationToken cancellationToken)
    {
        if (backward)
        {
            throw new GraphQLInputException("Backward pagination is not supported. Use first and after.");
        }

        var cursor = Cursor(after);
        var size = Size(first, options);
        long? total = null;

        async ValueTask<long> Total() => total ??= await query.CountAsync(cancellationToken).ConfigureAwait(false);

        if (size == 0)
        {
            // Nothing is read, but the client still learns whether there was anything to read.
            var more = cursor is null
                ? await Total().ConfigureAwait(false) > 0
                : (await query.PageAsync(1, cursor, cancellationToken).ConfigureAwait(false)).Items.Count > 0;

            return new ConnectionResult([], [], more, cursor is not null, null, null, Total);
        }

        var page = await query.PageAsync(size, cursor, cancellationToken).ConfigureAwait(false);
        var end = page.Next?.Token;
        var edges = page.Items
            .Select((node, index) => new EdgeResult(index == page.Items.Count - 1 ? end : null, node))
            .ToList();

        return new ConnectionResult(
            page.Items,
            edges,
            page.HasMore,
            cursor is not null,
            edges.Count == 0 ? null : edges[0].Cursor,
            end,
            Total);
    }

    private static int Size(int? first, ElephentityGraphQLOptions options)
    {
        if (first is null)
        {
            return options.DefaultPageSize;
        }

        return first < 0
            ? throw new GraphQLInputException("first must be non-negative.")
            : Math.Min(first.Value, options.MaxPageSize);
    }

    private static Elephentity.Runtime.Storage.Cursor? Cursor(string? after)
    {
        if (string.IsNullOrEmpty(after))
        {
            return null;
        }

        return IsWellFormed(after)
            ? Elephentity.Runtime.Storage.Cursor.Of(after)
            : throw new GraphQLInputException("Invalid pagination cursor.");
    }

    // A cursor is opaque, but not so opaque that a made-up string should reach storage: it is either the keyset cursor the runtime
    // hands out or the positional one the adaptors still accept.
    private static bool IsWellFormed(string token)
    {
        if (KeysetCursor.From(Elephentity.Runtime.Storage.Cursor.Of(token)) is not null)
        {
            return true;
        }

        var buffer = new byte[token.Length];

        if (!Convert.TryFromBase64String(token, buffer, out var written))
        {
            return false;
        }

        var text = Encoding.UTF8.GetString(buffer, 0, written);

        return text.StartsWith("offset:", StringComparison.Ordinal)
            && text.Length > "offset:".Length
            && text["offset:".Length..].All(char.IsAsciiDigit)
            && long.TryParse(text["offset:".Length..], NumberStyles.None, CultureInfo.InvariantCulture, out _);
    }
}
