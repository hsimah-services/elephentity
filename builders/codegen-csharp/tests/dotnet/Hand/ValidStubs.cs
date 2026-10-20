// The application's side of the Valid tree: stub implementations of every contract its spec generated.

using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Verification;
using Golden.Valid.Contract;
using Golden.Valid.Declared;
using Golden.Valid.Enums;
using Golden.Valid.Pattern.Contract;
using Golden.Valid.Types;
using VerificationResult = Elephentity.Runtime.Verification.Verification;
using GatewayRuntime = Elephentity.Runtime.Gateway.Runtime;

namespace Golden.Valid.Stubs;

/// <summary>What the side effects saw, in order.</summary>
public sealed class Calls
{
    public List<string> Seen { get; } = [];
}

public sealed class MoneyReader : IMoneyReadProcessor
{
    public Money Read(long stored) => new(stored);
}

public sealed class MoneyWriter : IMoneyWriteProcessor
{
    public VerificationResult Verify(Money value, IMutationContext context) =>
        value.Minor < 0 ? VerificationResult.Failed(new Violation("money.negative", "An amount cannot be negative.")) : VerificationResult.Ok();

    public long Write(Money value) => value.Minor;
}

public sealed class PublishAction : IPostPublishAction
{
    public ValueTask HandleAsync(PostPublishContext context, DateTimeOffset? at, CancellationToken cancellationToken = default)
    {
        context.SetStatus(PostStatus.Published);

        return ValueTask.CompletedTask;
    }
}

public sealed class PublishedQuery(GatewayRuntime runtime, PostHydrator hydrator) : IPostPublishedQuery
{
    public IEntityQuery<Post> Find(long? limit) =>
        runtime.Queries().Of(hydrator, Criteria.For("Post").Where(Filter.Equal("status", "published")));
}

public sealed class AuditEffect(Calls calls) : IPostAuditSideEffect
{
    public ValueTask HandleAsync(PostMutationContext context, CancellationToken cancellationToken = default)
    {
        calls.Seen.Add($"audit:{(context.IsCreate ? "create" : "update")}:{context.PendingTitle()}");

        return ValueTask.CompletedTask;
    }
}

public sealed class ReindexEffect(Calls calls) : IPostReindexSideEffect
{
    public ValueTask HandleAsync(PostMutationContext context, CancellationToken cancellationToken = default)
    {
        calls.Seen.Add("reindex");

        return ValueTask.CompletedTask;
    }
}

public sealed class PriceVerifier : IPostPriceVerifier
{
    public ValueTask<VerificationResult> VerifyAsync(Money? value, PostMutationContext context, CancellationToken cancellationToken = default) =>
        ValueTask.FromResult(value is { Minor: > 1_000_000 } ? VerificationResult.Failed(new Violation("price.too-high", "Too expensive.")) : VerificationResult.Ok());
}

public sealed class OwnerRead : IAuditableOwnerReadPolicy
{
    public ValueTask<PolicyDecision> DecideAsync(Pattern.IAuditable entity, IViewer viewer, CancellationToken cancellationToken = default) =>
        ValueTask.FromResult(PolicyDecision.Skip());
}

public sealed class ReporterRead : IPostReporterReadPolicy
{
    public ValueTask<PolicyDecision> DecideAsync(Post entity, IViewer viewer, CancellationToken cancellationToken = default) =>
        ValueTask.FromResult(entity.Title == "secret" ? PolicyDecision.Deny("secret posts are hidden") : PolicyDecision.Skip());
}

public sealed class NoBackwardsWrite : IPostNoBackwardsWritePolicy
{
    public ValueTask<PolicyDecision> DecideAsync(Post? entity, PostWriteContext context, IViewer viewer, CancellationToken cancellationToken = default)
    {
        var backwards = context.Operation == WriteOperation.Update
            && entity?.Status == PostStatus.Published
            && context.Arguments.TryGetValue("status", out var next) && next is "draft";

        return ValueTask.FromResult(backwards ? PolicyDecision.Deny("published posts stay published") : PolicyDecision.Allow());
    }
}
