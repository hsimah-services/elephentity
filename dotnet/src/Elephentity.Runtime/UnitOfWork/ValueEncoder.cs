using System.Buffers;
using System.Globalization;
using System.Text.Json;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Type;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Turns domain values into what the adaptor stores.
/// </summary>
/// <remarks>
/// The last step before a value leaves the framework, and the mirror of the read processors on the way back.
/// Anything the closed primitive set covers is handled here; declared types delegate to their write
/// processor, which by this point has already passed verification. A moment is stored as UTC ISO 8601 with a
/// fixed number of fractional digits (<c>o</c>), so the text sorts the way the moments do.
/// </remarks>
public sealed class ValueEncoder
{
    /// <summary>The format a moment is written in, always in UTC.</summary>
    public const string DateTimeFormat = "o";

    private readonly IReadOnlyDictionary<string, string> fieldTypes;
    private readonly IProcessorRegistry processors;

    /// <summary>An encoder for the project's fields.</summary>
    /// <param name="fieldTypes">"Entity.field" to declared type name.</param>
    /// <param name="processors">Where declared types' write processors come from.</param>
    public ValueEncoder(IReadOnlyDictionary<string, string> fieldTypes, IProcessorRegistry processors)
    {
        this.fieldTypes = fieldTypes;
        this.processors = processors;
    }

    /// <summary>Encodes one value for storage.</summary>
    /// <param name="entity">The entity.</param>
    /// <param name="field">The field.</param>
    /// <param name="value">The domain value.</param>
    /// <returns>A string, number, bool or null.</returns>
    public object? Encode(string entity, string field, object? value)
    {
        if (value is null)
        {
            return null;
        }

        if (fieldTypes.TryGetValue($"{entity}.{field}", out var type) && processors.Has(type))
        {
            return Scalar(processors.Write(type).Write(value), entity, field);
        }

        return Scalar(value, entity, field);
    }

    private static object? Scalar(object? value, string entity, string field) => value switch
    {
        null => null,
        string or bool or long or double => value,
        int or short or sbyte or byte or ushort => Convert.ToInt64(value, CultureInfo.InvariantCulture),
        uint number => (long)number,
        float number => (double)number,
        DateTimeOffset moment => moment.UtcDateTime.ToString(DateTimeFormat, CultureInfo.InvariantCulture),
        DateTime moment => moment.ToUniversalTime().ToString(DateTimeFormat, CultureInfo.InvariantCulture),
        EntityId id => id.Raw(),
        IReadOnlyList<object?> or IReadOnlyDictionary<string, object?> => Json(value, entity, field),
        _ => throw new InvalidOperationException(
            $"{entity}.{field} holds a {value.GetType().Name}, which nothing knows how to store. "
            + "A declared type needs a write processor."),
    };

    private static string Json(object value, string entity, string field)
    {
        var buffer = new ArrayBufferWriter<byte>();

        using (var writer = new Utf8JsonWriter(buffer))
        {
            try
            {
                WriteJson(writer, value);
            }
            catch (InvalidOperationException exception)
            {
                throw new InvalidOperationException($"{entity}.{field} could not be encoded as JSON: {exception.Message}", exception);
            }
        }

        return System.Text.Encoding.UTF8.GetString(buffer.WrittenSpan);
    }

    private static void WriteJson(Utf8JsonWriter writer, object? value)
    {
        switch (value)
        {
            case null:
                writer.WriteNullValue();
                break;
            case string text:
                writer.WriteStringValue(text);
                break;
            case bool flag:
                writer.WriteBooleanValue(flag);
                break;
            case long or int or short or sbyte or byte or ushort or uint:
                writer.WriteNumberValue(Convert.ToInt64(value, CultureInfo.InvariantCulture));
                break;
            case double or float:
                writer.WriteNumberValue(Convert.ToDouble(value, CultureInfo.InvariantCulture));
                break;
            case IReadOnlyDictionary<string, object?> map:
                writer.WriteStartObject();

                foreach (var (key, item) in map)
                {
                    writer.WritePropertyName(key);
                    WriteJson(writer, item);
                }

                writer.WriteEndObject();
                break;
            case IReadOnlyList<object?> list:
                writer.WriteStartArray();

                foreach (var item in list)
                {
                    WriteJson(writer, item);
                }

                writer.WriteEndArray();
                break;
            default:
                throw new InvalidOperationException($"a {value.GetType().Name} is not JSON data.");
        }
    }
}
