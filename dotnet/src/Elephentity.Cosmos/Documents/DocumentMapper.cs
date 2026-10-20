using System.Globalization;
using System.Text.Json;
using System.Text.Json.Nodes;
using Elephentity.Cosmos.Manifest;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;

namespace Elephentity.Cosmos;

/// <summary>Turns stored values into documents and documents back into records.</summary>
internal static class DocumentMapper
{
    /// <summary>The document for a new row: every field present (a missing one is an explicit null) and every unique slot filled.</summary>
    /// <remarks>
    /// Every field is written even when the caller did not supply it. A Cosmos <c>ORDER BY</c> leaves out documents that
    /// lack the property, so a row created without a value for a sorted field would vanish from every sorted list.
    /// </remarks>
    public static JsonObject NewDocument(
        CosmosStorageManifest manifest,
        CosmosEntityLayout layout,
        string id,
        IReadOnlyDictionary<string, object?> values)
    {
        foreach (var field in values.Keys)
        {
            _ = layout.Property(field);
        }

        var document = new JsonObject
        {
            [DocumentProperties.Id] = id,
            [DocumentProperties.PartitionKey] = manifest.PartitionKey,
            [DocumentProperties.Type] = layout.Entity,
        };

        foreach (var (field, property) in layout.Fields)
        {
            document[property] = ToNode(values.GetValueOrDefault(field), layout.Entity, field);
        }

        for (var slot = 0; slot < manifest.UniqueSlots; slot++)
        {
            document[DocumentProperties.UniqueSlot(slot)] = slot < layout.UniqueKeys.Count
                ? UniqueKeys.Value(layout.Entity, layout.UniqueKeys[slot], values.GetValueOrDefault(layout.UniqueKeys[slot].Field), id)
                : UniqueKeys.Filler(layout.Entity, id);
        }

        return document;
    }

    /// <summary>A join document: the link, and a filler in every unique slot so it can never collide.</summary>
    public static JsonObject JoinDocument(CosmosStorageManifest manifest, CosmosEdgePlacement placement, string from, string to)
    {
        var id = placement.JoinId(from, to);
        var document = new JsonObject
        {
            [DocumentProperties.Id] = id,
            [DocumentProperties.PartitionKey] = manifest.PartitionKey,
            [DocumentProperties.Type] = placement.JoinType,
            [DocumentProperties.From] = from,
            [DocumentProperties.To] = to,
        };

        for (var slot = 0; slot < manifest.UniqueSlots; slot++)
        {
            document[DocumentProperties.UniqueSlot(slot)] = UniqueKeys.Filler(placement.JoinType, id);
        }

        return document;
    }

    /// <summary>The record a document is, with the spec's field names.</summary>
    public static Record ToRecord(CosmosEntityLayout layout, JsonObject document, string? parent = null)
    {
        var id = document[DocumentProperties.Id]?.GetValue<string>()
            ?? throw new InvalidOperationException($"A {layout.Entity} document came back without an id.");
        var values = new Dictionary<string, object?>(layout.Fields.Count);

        foreach (var (field, property) in layout.Fields)
        {
            values[field] = FromNode(document[property]);
        }

        if (parent is not null)
        {
            values[EdgeFilter.ParentColumn] = parent;
        }

        return new Record(layout.Entity, EntityId.Of(id), values);
    }

    /// <summary>A stored value as JSON: a string, number, boolean or null, which is all the encoder produces.</summary>
    public static JsonNode? ToNode(object? value, string entity, string field) => value switch
    {
        null => null,
        string text => JsonValue.Create(text),
        bool flag => JsonValue.Create(flag),
        long number => JsonValue.Create(number),
        int number => JsonValue.Create(number),
        short or sbyte or byte or ushort => JsonValue.Create(Convert.ToInt64(value, CultureInfo.InvariantCulture)),
        uint number => JsonValue.Create((long)number),
        double number => JsonValue.Create(number),
        float number => JsonValue.Create((double)number),
        decimal number => JsonValue.Create(number),
        EntityId id => JsonValue.Create(id.ToString()),
        _ => throw new InvalidOperationException(
            $"{entity}.{field} holds a {value.GetType().Name}, which the Cosmos adaptor cannot store. Values are encoded to strings, numbers and booleans before they reach it."),
    };

    /// <summary>A document value as the plain value a record holds.</summary>
    public static object? FromNode(JsonNode? node)
    {
        if (node is null)
        {
            return null;
        }

        var element = node.GetValueKind() switch
        {
            JsonValueKind.String => (object?)node.GetValue<string>(),
            JsonValueKind.True => true,
            JsonValueKind.False => false,
            JsonValueKind.Number => Number(node),
            JsonValueKind.Null => null,
            _ => node.ToJsonString(),
        };

        return element;
    }

    private static object Number(JsonNode node)
    {
        var value = node.AsValue();

        if (value.TryGetValue<long>(out var integer))
        {
            return integer;
        }

        return value.GetValue<double>();
    }

    /// <summary>Whether an id can be written into a patch filter predicate, which cannot take parameters.</summary>
    public static bool IsSafeId(string id) => id.Length is > 0 and <= 128 && id.All(c => char.IsAsciiLetterOrDigit(c) || c is '-' or '_' or '|' or ':' or '.');
}
