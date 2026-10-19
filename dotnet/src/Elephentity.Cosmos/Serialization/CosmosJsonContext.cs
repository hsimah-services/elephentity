using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.Json.Serialization;

namespace Elephentity.Cosmos.Serialization;

/// <summary>
/// The types the adaptor sends to and reads from Cosmos, for source-generated serialization.
/// </summary>
/// <remarks>
/// Documents are <see cref="JsonObject" />s, never classes: the adaptor does not know an entity's shape, only its layout,
/// so there is nothing to reflect over. Query parameters are strings, numbers, booleans and string arrays.
/// </remarks>
[JsonSourceGenerationOptions(WriteIndented = false)]
[JsonSerializable(typeof(JsonObject))]
[JsonSerializable(typeof(JsonObject[]))]
[JsonSerializable(typeof(List<JsonObject>))]
[JsonSerializable(typeof(JsonNode))]
[JsonSerializable(typeof(JsonElement))]
[JsonSerializable(typeof(JsonArray))]
[JsonSerializable(typeof(string))]
[JsonSerializable(typeof(string[]))]
[JsonSerializable(typeof(long))]
[JsonSerializable(typeof(long[]))]
[JsonSerializable(typeof(int))]
[JsonSerializable(typeof(double))]
[JsonSerializable(typeof(double[]))]
[JsonSerializable(typeof(bool))]
[JsonSerializable(typeof(object[]))]
internal sealed partial class CosmosJsonContext : JsonSerializerContext
{
}
