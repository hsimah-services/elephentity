using Elephentity.Runtime.Capability;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.SideEffect;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Verification;
using Caps = Elephentity.Runtime.Capability.Capabilities;
using Record = Elephentity.Runtime.Storage.Record;
using Ver = Elephentity.Runtime.Verification.Verification;

namespace Elephentity.Runtime.Tests.UnitOfWork;

/// <summary>
/// A spy, not a store: reads never see what writes recorded. It proves the unit of work calls the port
/// correctly and in the right order; read-after-write is the memory driver's conformance job.
/// </summary>
internal class FakeStorage : IStorageAdaptor
{
    private long nextId = 1;

    public List<WriteBatch> Batches { get; } = [];

    public List<string> Log { get; } = [];

    public Dictionary<string, List<Record>> Records { get; } = [];

    public long CountResult { get; set; }

    public Exception? FailCommit { get; set; }

    public List<Criteria> Counted { get; } = [];

    public List<Criteria> Queried { get; } = [];

    public Caps Capabilities { get; } = new("fake", Elephentity.Runtime.Capability.Capability.Transactions);

    public virtual Task<Record?> GetAsync(string entity, EntityId id, CancellationToken cancellationToken = default) =>
        Task.FromResult<Record?>(null);

    public Task<IReadOnlyList<Record>> GetManyAsync(
        string entity,
        IReadOnlyList<EntityId> ids,
        CancellationToken cancellationToken = default) => Task.FromResult<IReadOnlyList<Record>>([]);

    public virtual Task<Page<Record>> QueryAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        Queried.Add(criteria);

        return Task.FromResult(new Page<Record>(Records.GetValueOrDefault(criteria.Entity) ?? []));
    }

    public Task<long> CountAsync(Criteria criteria, CancellationToken cancellationToken = default)
    {
        Counted.Add(criteria);

        return Task.FromResult(CountResult);
    }

    public Task<WriteResult> WriteAsync(WriteBatch batch, CancellationToken cancellationToken = default)
    {
        Batches.Add(batch);
        var result = new WriteResult();

        foreach (var operation in batch.Operations)
        {
            Log.Add($"{operation.GetType().Name.ToLowerInvariant()} {operation.Entity}");

            if (operation is Insert insert)
            {
                result.Assign(insert.PendingId, EntityId.Of(nextId++));
            }
        }

        return Task.FromResult(result);
    }

    public async Task<T> TransactionAsync<T>(Func<CancellationToken, Task<T>> work, CancellationToken cancellationToken = default)
    {
        Log.Add("begin");

        try
        {
            var value = await work(cancellationToken);

            if (FailCommit is not null)
            {
                throw FailCommit;
            }

            Log.Add("commit");

            return value;
        }
        catch (Exception)
        {
            Log.Add("rollback");

            throw;
        }
    }
}

internal sealed class StubVerifiers(Dictionary<string, Ver> results) : IEntityVerifiers
{
    public ValueTask<Ver> VerifyAsync(
        string field,
        object? value,
        IMutationContext context,
        CancellationToken cancellationToken = default) =>
        ValueTask.FromResult(results.GetValueOrDefault(field) ?? Ver.Ok());

    public IReadOnlyList<string> VerifiedFields() => [.. results.Keys];
}

internal sealed class RecordingSideEffects : IEntitySideEffects
{
    private readonly Dictionary<SideEffectPhase, Exception> failures = [];

    public List<string> Calls { get; } = [];

    public void FailOn(SideEffectPhase phase, Exception failure) => failures[phase] = failure;

    public IEnumerable<Func<CancellationToken, ValueTask>> Handlers(
        SideEffectPhase phase,
        SideEffectEvent sideEffectEvent,
        IMutableMutationContext context)
    {
        yield return _ =>
        {
            Calls.Add($"{phase.ToWire()}:{sideEffectEvent.ToWire()}:{context.Entity}:{context.Id}");

            return failures.TryGetValue(phase, out var failure) ? throw failure : ValueTask.CompletedTask;
        };
    }
}
