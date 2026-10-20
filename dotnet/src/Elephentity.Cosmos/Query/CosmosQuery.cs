using Microsoft.Azure.Cosmos;

namespace Elephentity.Cosmos.Query;

/// <summary>A parameterised query: the text, and every value that goes in it.</summary>
/// <remarks>Values are never written into the text. Property names are, and only after the manifest vouched for them.</remarks>
/// <param name="Text">The Cosmos SQL.</param>
/// <param name="Parameters">Parameter name (with its <c>@</c>) to value.</param>
internal sealed record CosmosQuery(string Text, IReadOnlyList<KeyValuePair<string, object?>> Parameters)
{
    /// <summary>The query as the SDK takes it.</summary>
    /// <returns>The definition.</returns>
    public QueryDefinition ToDefinition()
    {
        var definition = new QueryDefinition(Text);

        foreach (var (name, value) in Parameters)
        {
            definition = definition.WithParameter(name, value);
        }

        return definition;
    }
}
