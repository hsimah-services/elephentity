using System.Text.Json.Nodes;

namespace Elephentity.Cosmos.Writes;

/// <summary>
/// The writes of one transaction, waiting to become one transactional batch.
/// </summary>
/// <remarks>
/// <para>
/// The adaptor makes ids itself, so a row's id is known the moment it is staged and nothing has to be written for the
/// runtime to link to it. That is what lets a whole unit of work (its rows, its links and its deletions, which the runtime
/// writes as separate calls) be one atomic batch.
/// </para>
/// <para>
/// Staging folds: a patch of a document created earlier in the same buffer is applied to the document being created,
/// and two patches of one document become one. The batch then has fewer operations, which matters because it can hold
/// only 100, and it never patches something that does not exist yet.
/// </para>
/// </remarks>
internal sealed class WriteBuffer
{
    private readonly List<StagedOperation> operations = [];
    private readonly Dictionary<string, CreateOperation> creates = [];

    public IReadOnlyList<StagedOperation> Operations => operations;

    public void Add(StagedOperation operation)
    {
        switch (operation)
        {
            case CreateOperation create:
                creates[create.Id] = create;
                operations.Add(create);
                break;
            case PatchOperation patch when creates.TryGetValue(patch.Id, out var created):
                Apply(created.Document, patch);
                break;
            case PatchOperation patch when Mergeable(patch) is { } earlier:
                foreach (var (property, value) in patch.Sets)
                {
                    earlier.Sets[property] = value;
                }

                break;
            case DeleteOperation delete when creates.Remove(delete.Id, out var created):
                // Created and deleted in one transaction: the document never needs to exist.
                operations.Remove(created);
                break;
            default:
                operations.Add(operation);
                break;
        }
    }

    private static void Apply(JsonObject document, PatchOperation patch)
    {
        if (patch.Guard is { } guard && document[guard.Property]?.GetValue<string>() != guard.Value)
        {
            return;
        }

        foreach (var (property, value) in patch.Sets)
        {
            document[property] = value?.DeepClone();
        }
    }

    /// <summary>An earlier unguarded patch of the same document that this one can be folded into.</summary>
    private PatchOperation? Mergeable(PatchOperation patch) =>
        patch.Guard is null
            ? operations.OfType<PatchOperation>().FirstOrDefault(earlier => earlier.Id == patch.Id && earlier.Guard is null && earlier.Optional == patch.Optional && earlier.Purpose == patch.Purpose)
            : null;
}
