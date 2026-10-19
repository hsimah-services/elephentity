// The application's side of each generated tree: a stub for every contract its spec generated, and nothing more. The point of these
// is the GraphQL layer, so the policies allow and the verifiers pass.

using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using VerificationResult = Elephentity.Runtime.Verification.Verification;
using GatewayRuntime = Elephentity.Runtime.Gateway.Runtime;

namespace Golden.Book.Stubs
{
    public sealed class Search(GatewayRuntime runtime, Golden.Book.BookHydrator hydrator) : Golden.Book.Contract.IBookSearchQuery
    {
        public IEntityQuery<Golden.Book.Book> Find(string? text) =>
            runtime.Queries().Of(hydrator, text is null ? Criteria.For("Book") : Criteria.For("Book").Where(Filter.Contains("title", text)));
    }

    public sealed class Rename : Golden.Book.Contract.IBookRenameAction
    {
        public ValueTask HandleAsync(Golden.Book.BookRenameContext context, string title, CancellationToken cancellationToken = default)
        {
            context.SetTitle(title);

            return ValueTask.CompletedTask;
        }
    }
}

namespace Golden.Relations.Stubs
{
    public sealed class MoneyReader : Golden.Relations.Types.IMoneyReadProcessor
    {
        public Golden.Relations.Declared.Money Read(long stored) => new(stored);
    }

    public sealed class MoneyWriter : Golden.Relations.Types.IMoneyWriteProcessor
    {
        public VerificationResult Verify(Golden.Relations.Declared.Money value, IMutationContext context) => VerificationResult.Ok();

        public long Write(Golden.Relations.Declared.Money value) => value.Minor;
    }

    public sealed class Publish : Golden.Relations.Contract.IPostPublishAction
    {
        public ValueTask HandleAsync(Golden.Relations.PostPublishContext context, DateTimeOffset? at, CancellationToken cancellationToken = default)
        {
            context.SetStatus(Golden.Relations.Enums.PostStatus.Published);

            return ValueTask.CompletedTask;
        }
    }

    public sealed class Published(GatewayRuntime runtime, Golden.Relations.PostHydrator hydrator) : Golden.Relations.Contract.IPostPublishedQuery
    {
        public IEntityQuery<Golden.Relations.Post> Find(long? limit) =>
            runtime.Queries().Of(hydrator, Criteria.For("Post").Where(Filter.Equal("status", "published")));
    }

    public sealed class Effect : Golden.Relations.Contract.IPostAuditSideEffect, Golden.Relations.Contract.IPostReindexSideEffect
    {
        public ValueTask HandleAsync(Golden.Relations.PostMutationContext context, CancellationToken cancellationToken = default) => ValueTask.CompletedTask;
    }

    public sealed class Price : Golden.Relations.Contract.IPostPriceVerifier
    {
        public ValueTask<VerificationResult> VerifyAsync(Golden.Relations.Declared.Money? value, Golden.Relations.PostMutationContext context, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(VerificationResult.Ok());
    }

    public sealed class Allow : Golden.Relations.Pattern.Contract.IAuditableOwnerReadPolicy, Golden.Relations.Contract.IPostReporterReadPolicy, Golden.Relations.Contract.IPostNoBackwardsWritePolicy
    {
        public ValueTask<PolicyDecision> DecideAsync(Golden.Relations.Pattern.IAuditable entity, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(PolicyDecision.Allow());

        public ValueTask<PolicyDecision> DecideAsync(Golden.Relations.Post entity, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(PolicyDecision.Allow());

        public ValueTask<PolicyDecision> DecideAsync(Golden.Relations.Post? entity, Golden.Relations.PostWriteContext context, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(PolicyDecision.Allow());
    }
}

namespace Golden.Clog.Stubs
{
    public sealed class Search(GatewayRuntime runtime, Golden.Clog.ItemHydrator hydrator) : Golden.Clog.Contract.IItemSearchQuery
    {
        public IEntityQuery<Golden.Clog.Item> Find(string term) =>
            runtime.Queries().Of(hydrator, Criteria.For("Item").Where(Filter.Contains("name", term)));
    }

    public sealed class Discontinue : Golden.Clog.Contract.IItemDiscontinueAction
    {
        public ValueTask HandleAsync(Golden.Clog.ItemDiscontinueContext context, string reason, CancellationToken cancellationToken = default) => ValueTask.CompletedTask;
    }

    public sealed class Verify : Golden.Clog.Contract.IItemDefaultExpiryUnitVerifier, Golden.Clog.Contract.IItemDefaultExpiryValueVerifier
    {
        public ValueTask<VerificationResult> VerifyAsync(Golden.Clog.Enums.ExpiryUnit? value, Golden.Clog.ItemMutationContext context, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(VerificationResult.Ok());

        public ValueTask<VerificationResult> VerifyAsync(long? value, Golden.Clog.ItemMutationContext context, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(VerificationResult.Ok());
    }

    public sealed class Availability : Golden.Clog.Contract.IInventorySiteAvailabilitySideEffect
    {
        public ValueTask HandleAsync(Golden.Clog.InventoryPreCommitContext context, CancellationToken cancellationToken = default) => ValueTask.CompletedTask;
    }

    public sealed class Allow : Golden.Clog.Contract.IItemStaffWritePolicy, Golden.Clog.Pattern.Contract.IClogPostSignedInReadPolicy
    {
        public ValueTask<PolicyDecision> DecideAsync(Golden.Clog.Item? entity, Golden.Clog.ItemWriteContext context, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(PolicyDecision.Allow());

        public ValueTask<PolicyDecision> DecideAsync(Golden.Clog.Pattern.IClogPost entity, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(PolicyDecision.Allow());
    }
}
