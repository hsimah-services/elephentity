using System.Text.Json;
using System.Text.Json.Nodes;
using Microsoft.Azure.Cosmos;

namespace Elephentity.Cosmos.Serialization;

/// <summary>
/// The Cosmos SDK's serializer for the adaptor's documents, on System.Text.Json.
/// </summary>
/// <remarks>
/// The SDK's default is Newtonsoft.Json, which reflects over types and keeps a second JSON stack loaded. The adaptor
/// has no document classes to reflect over (a document is a <see cref="System.Text.Json.Nodes.JsonObject" />), so this
/// is a thin shim over a source-generated context. The SDK serializes its own system types (account and container
/// properties, responses) itself and only hands this the user types, which are the ones registered in
/// <see cref="CosmosJsonContext" />. Anything else is refused by name rather than serialized by guesswork.
/// </remarks>
public sealed class CosmosSystemTextJsonSerializer : CosmosSerializer
{
    /// <inheritdoc />
    public override T FromStream<T>(Stream stream)
    {
        using (stream)
        {
            if (typeof(Stream).IsAssignableFrom(typeof(T)))
            {
                return (T)(object)stream;
            }

            var value = JsonSerializer.Deserialize(stream, Info(typeof(T)));

            return (T)value!;
        }
    }

    /// <inheritdoc />
    public override Stream ToStream<T>(T input)
    {
        var stream = new MemoryStream();

        if (input is null)
        {
            stream.Write("null"u8);
        }
        else if (input is JsonNode node)
        {
            // Any node, whatever its concrete type (a value, an object, an array): they know how to write themselves.
            using var writer = new Utf8JsonWriter(stream);
            node.WriteTo(writer);
        }
        else
        {
            JsonSerializer.Serialize(stream, input, Info(input.GetType()));
        }

        stream.Position = 0;

        return stream;
    }

    private static System.Text.Json.Serialization.Metadata.JsonTypeInfo Info(System.Type type) =>
        CosmosJsonContext.Default.GetTypeInfo(type)
        ?? throw new NotSupportedException(
            $"The Cosmos adaptor does not serialize {type.FullName}: documents are JsonObject and parameters are strings, numbers, booleans or string arrays.");
}
