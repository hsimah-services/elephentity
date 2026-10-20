using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.UnitOfWork;

/// <summary>
/// Asks whether a value marked unique is already taken.
/// </summary>
/// <remarks>
/// Without it a duplicate is a raw storage exception: the driver's message and an index name no caller has ever
/// heard of. A uniqueness failure deserves the kind of answer <c>onDelete: restrict</c> gives, naming the thing,
/// so it becomes a violation with a field path and joins whatever else the commit found wrong.
/// <b>The index is still the guarantee.</b> This runs before the transaction opens, so two concurrent creates can
/// both pass it and one will still fail on the constraint. The check makes the ordinary case a good error, and
/// the database keeps being right about the race.
/// </remarks>
public sealed class UniquenessCheck
{
    private readonly IStorageAdaptor storage;
    private readonly ValueEncoder encoder;
    private readonly IReadOnlyDictionary<string, IReadOnlyList<string>> fields;

    /// <summary>A check over the project's unique fields.</summary>
    /// <param name="storage">Where to look for a taken value.</param>
    /// <param name="encoder">Encodes a value the way it is stored, so it compares equal.</param>
    /// <param name="fields">Entity to the fields carrying <c>unique</c>.</param>
    public UniquenessCheck(
        IStorageAdaptor storage,
        ValueEncoder encoder,
        IReadOnlyDictionary<string, IReadOnlyList<string>>? fields = null)
    {
        this.storage = storage;
        this.encoder = encoder;
        this.fields = fields ?? new Dictionary<string, IReadOnlyList<string>>();
    }

    /// <summary>Checks a mutation's unique fields.</summary>
    /// <param name="mutation">The mutation.</param>
    /// <param name="cancellationToken">Cancels the reads.</param>
    /// <returns>A violation for each unique field whose new value is taken.</returns>
    public async ValueTask<IReadOnlyList<FieldViolation>> CheckAsync(
        Mutation.Mutation mutation,
        CancellationToken cancellationToken = default)
    {
        var changes = mutation.Changes();
        var violations = new List<FieldViolation>();

        foreach (var field in fields.GetValueOrDefault(mutation.Entity) ?? [])
        {
            if (!changes.TryGetValue(field, out var changed))
            {
                continue;
            }

            // NULL never collides with NULL, and a nullable unique column is how "at most one, if any" is
            // spelled. Checking it would forbid the second row with no barcode.
            var value = encoder.Encode(mutation.Entity, field, changed);

            if (value is null)
            {
                continue;
            }

            if (await IsTakenAsync(mutation, field, value, cancellationToken).ConfigureAwait(false))
            {
                violations.Add(new FieldViolation(
                    mutation.Entity,
                    field,
                    new Violation("field.unique", "Another row already holds this value, and the field is unique.")));
            }
        }

        return violations;
    }

    private async ValueTask<bool> IsTakenAsync(
        Mutation.Mutation mutation,
        string field,
        object value,
        CancellationToken cancellationToken)
    {
        var criteria = Criteria.For(mutation.Entity).Where(Filter.Equal(field, value));

        // On update the row itself holds the value, and colliding with yourself is not a collision.
        if (mutation.Target is EntityId target)
        {
            criteria = criteria.Where(Filter.NotEqual("id", target.Raw()));
        }

        return await storage.CountAsync(criteria, cancellationToken).ConfigureAwait(false) > 0;
    }
}
