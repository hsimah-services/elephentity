using System.Globalization;
using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos;

/// <summary>
/// The total order of a criteria applied to records already in memory.
/// </summary>
/// <remarks>
/// Needed only when a batch asks for several parents at once: a row appears once per parent, which Cosmos cannot
/// produce, so those rows are fetched and ordered here. It orders as the other adaptors do (the terms in turn, then the
/// id, then the parent) with nothing sorting before a missing value, numbers as numbers and unlike kinds by kind.
/// </remarks>
internal sealed class RecordOrdering(IReadOnlyList<Order> orders) : IComparer<Record>
{
    public int Compare(Record? left, Record? right)
    {
        foreach (var order in orders)
        {
            var comparison = Compare(Value(left!, order.Field), Value(right!, order.Field));

            if (comparison != 0)
            {
                return order.Direction == Direction.Descending ? -comparison : comparison;
            }
        }

        return Compare(left!.Value(EdgeFilter.ParentColumn), right!.Value(EdgeFilter.ParentColumn));
    }

    /// <summary>Whether <paramref name="row" /> comes strictly after <paramref name="position" />.</summary>
    public bool IsAfter(Record row, KeysetCursor position)
    {
        for (var index = 0; index < orders.Count - 1; index++)
        {
            var comparison = Compare(Value(row, orders[index].Field), position.SortValues[index]);

            if (comparison != 0)
            {
                return (orders[index].Direction == Direction.Descending ? -comparison : comparison) > 0;
            }
        }

        var byId = Compare(row.Id.Raw(), position.Id.Raw());

        return (orders[^1].Direction == Direction.Descending ? -byId : byId) > 0;
    }

    private static object? Value(Record record, string field) => field == "id" ? record.Id.Raw() : record.Value(field);

    private static int Compare(object? left, object? right)
    {
        if (left is null || right is null)
        {
            return (left is null ? 0 : 1) - (right is null ? 0 : 1);
        }

        if (IsNumber(left) && IsNumber(right))
        {
            return Convert.ToDouble(left, CultureInfo.InvariantCulture).CompareTo(Convert.ToDouble(right, CultureInfo.InvariantCulture));
        }

        if (left is string a && right is string b)
        {
            return Math.Sign(string.CompareOrdinal(a, b));
        }

        if (left is bool x && right is bool y)
        {
            return x.CompareTo(y);
        }

        return Rank(left).CompareTo(Rank(right));
    }

    private static bool IsNumber(object value) => value is long or int or double or float or decimal;

    private static int Rank(object value) => IsNumber(value) ? 0 : value is string ? 1 : 2;
}
