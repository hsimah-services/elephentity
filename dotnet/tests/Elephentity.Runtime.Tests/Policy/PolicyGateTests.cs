using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Microsoft.Extensions.Logging;

namespace Elephentity.Runtime.Tests.Policy;

public sealed class PolicyGateTests
{
    [Fact]
    public async Task Allow_wins_and_ungated_entities_do_not_run_policies()
    {
        var policies = new StubPolicies((_, _) => PolicyDecision.Allow());
        var catalogue = new StubCatalogue()
            .Reading("Post", policies)
            .Reading("PublicPost", new NoPolicies());
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider());
        var instance = new object();

        Assert.Same(instance, await gate.PermitAsync("Post", instance, TestContext.Current.CancellationToken));
        Assert.Same(instance, await gate.PermitAsync("PublicPost", instance, TestContext.Current.CancellationToken));
        Assert.Equal(1, policies.Calls);
    }

    [Fact]
    public async Task Deny_is_refused_at_the_gate()
    {
        var catalogue = new StubCatalogue()
            .Reading("Post", new StubPolicies((_, _) => PolicyDecision.Deny("Not allowed.")));
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider());

        var refused = await Assert.ThrowsAsync<AccessDeniedException>(async () => await gate.PermitAsync("Post", new object(), TestContext.Current.CancellationToken));

        Assert.Equal("Post: Not allowed. (policy unknown)", refused.Message);
    }

    [Fact]
    public async Task Skip_is_refused_too_because_only_allow_lets_a_read_through()
    {
        var catalogue = new StubCatalogue()
            .Reading("Post", new StubPolicies((_, _) => PolicyDecision.Skip("No opinion.")));
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider());

        await Assert.ThrowsAsync<AccessDeniedException>(async () => await gate.PermitAsync("Post", new object(), TestContext.Current.CancellationToken));
    }

    [Fact]
    public async Task A_refusal_names_the_policy_that_decided()
    {
        var catalogue = new StubCatalogue().Reading(
            "Post",
            new StubPolicies((_, _) => PolicyDecision.Deny("Drafts are private.").WithPolicy("owner")));
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider());

        var refused = await Assert.ThrowsAsync<AccessDeniedException>(async () => await gate.PermitAsync("Post", new object(), TestContext.Current.CancellationToken));

        Assert.Equal("owner", refused.Policy);
        Assert.Equal("Drafts are private.", refused.Reason);
        Assert.Equal("Post", refused.Entity);
        Assert.Equal("Post: Drafts are private. (policy \"owner\")", refused.Message);
    }

    [Fact]
    public async Task Retain_keeps_what_the_viewer_may_read_in_order_and_logs_each_drop_at_debug()
    {
        var visible = new object();
        var hidden = new object();
        var alsoVisible = new object();
        var catalogue = new StubCatalogue().Reading(
            "Post",
            new StubPolicies((entity, _) => ReferenceEquals(entity, hidden)
                ? PolicyDecision.Deny("Hidden.").WithPolicy("visibility")
                : PolicyDecision.Allow()));
        var log = new CapturingLogger();
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider(), log);

        var retained = await gate.RetainAsync("Post", [visible, hidden, alsoVisible], TestContext.Current.CancellationToken);

        Assert.Equal([visible, alsoVisible], retained);

        var entry = Assert.Single(log.Entries);
        Assert.Equal(LogLevel.Debug, entry.Level);
        Assert.Contains("Entity=Post", entry.Message, StringComparison.Ordinal);
        Assert.Contains("Policy=visibility", entry.Message, StringComparison.Ordinal);
        Assert.Contains("Reason=Hidden.", entry.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Retain_returns_an_ungated_list_untouched_and_never_asks()
    {
        var gate = new ReadGate(new StubCatalogue(), new AnonymousViewerProvider());
        IReadOnlyList<object> everything = [new object(), new object()];

        Assert.Same(everything, await gate.RetainAsync("Post", everything, TestContext.Current.CancellationToken));
        Assert.False(gate.Guards("Post"));
    }

    [Fact]
    public async Task A_policy_that_has_to_wait_is_awaited_not_blocked_on()
    {
        var catalogue = new StubCatalogue().Reading("Post", new SlowPolicies(_ => PolicyDecision.Deny("Not a member.")));
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider());

        var refused = await Assert.ThrowsAsync<AccessDeniedException>(async () => await gate.PermitAsync("Post", new object(), TestContext.Current.CancellationToken));

        Assert.Equal("Not a member.", refused.Reason);
    }

    [Fact]
    public async Task Retain_awaits_each_policy_and_honours_cancellation()
    {
        var visible = new object();
        var hidden = new object();
        var catalogue = new StubCatalogue().Reading(
            "Post",
            new SlowPolicies(entity => ReferenceEquals(entity, hidden) ? PolicyDecision.Deny("Hidden.") : PolicyDecision.Allow()));
        var gate = new ReadGate(catalogue, new AnonymousViewerProvider());

        Assert.Equal([visible], await gate.RetainAsync("Post", [visible, hidden], TestContext.Current.CancellationToken));

        using var cancelled = new CancellationTokenSource();
        await cancelled.CancelAsync();

        await Assert.ThrowsAsync<OperationCanceledException>(
            async () => await gate.RetainAsync("Post", [visible], cancelled.Token));
    }

    [Fact]
    public async Task An_entity_with_read_policies_is_guarded()
    {
        var catalogue = new StubCatalogue().Reading("Post", new StubPolicies((_, _) => PolicyDecision.Allow()));

        Assert.True(new ReadGate(catalogue, new AnonymousViewerProvider()).Guards("Post"));
    }

    [Fact]
    public async Task The_gate_asks_the_policy_about_the_viewer_the_provider_supplies()
    {
        IViewer? seen = null;
        var catalogue = new StubCatalogue().Reading("Post", new StubPolicies((_, viewer) =>
        {
            seen = viewer;

            return PolicyDecision.Allow();
        }));

        await new ReadGate(catalogue, new AnonymousViewerProvider()).PermitAsync("Post", new object(), TestContext.Current.CancellationToken);

        Assert.IsType<AnonymousViewer>(seen);
    }

    [Fact]
    public async Task Write_gate_runs_before_the_caller_mutation()
    {
        var policies = new StubWritePolicies((_, _, _) => PolicyDecision.Deny("Writes are closed."));
        var catalogue = new StubCatalogue().Writing("Post", policies);
        var gate = new WriteGate(catalogue, new AnonymousViewerProvider());
        var pending = new PendingId("Post");

        var refused = await Assert.ThrowsAsync<AccessDeniedException>(
            async () => await gate.PermitAsync("Post", null, PendingWrite.Create("Post", new EmptyMutation("Post", pending)), TestContext.Current.CancellationToken));

        Assert.Equal("Writes are closed.", refused.Reason);
        Assert.Equal(1, policies.Calls);
    }

    [Fact]
    public async Task Write_gate_lets_an_allowed_write_through_and_skips_ungated_entities()
    {
        var policies = new StubWritePolicies((_, _, _) => PolicyDecision.Allow());
        var catalogue = new StubCatalogue().Writing("Post", policies);
        var gate = new WriteGate(catalogue, new AnonymousViewerProvider());

        await gate.PermitAsync("Post", new object(), PendingWrite.Delete("Post"), TestContext.Current.CancellationToken);
        await gate.PermitAsync("Open", null, PendingWrite.Delete("Open"), TestContext.Current.CancellationToken);

        Assert.Equal(1, policies.Calls);
    }

    [Fact]
    public async Task Write_gate_hands_the_policy_the_original_and_what_the_write_is()
    {
        var original = new object();
        object? sawOriginal = null;
        IWriteContext? sawContext = null;
        var catalogue = new StubCatalogue().Writing("Post", new StubWritePolicies((entity, context, _) =>
        {
            sawOriginal = entity;
            sawContext = context;

            return PolicyDecision.Allow();
        }));
        var mutation = new EmptyMutation("Post", EntityId.Of(1), new ActionCall("publish"));
        var arguments = new Dictionary<string, object?> { ["at"] = "noon" };

        await new WriteGate(catalogue, new AnonymousViewerProvider())
            .PermitAsync("Post", original, PendingWrite.ForAction("Post", "publish", arguments, mutation), TestContext.Current.CancellationToken);

        Assert.Same(original, sawOriginal);
        Assert.NotNull(sawContext);
        Assert.Equal(WriteOperation.Action, sawContext.Operation);
        Assert.Equal("publish", sawContext.Action);
        Assert.Equal("noon", sawContext.Arguments["at"]);
        Assert.Equal("publish", Assert.Single(sawContext.Actions).Name);
        Assert.Same(mutation, sawContext.Mutation);
    }

    [Fact]
    public async Task A_pending_write_knows_its_operation_and_a_delete_has_no_mutation()
    {
        var mutation = new EmptyMutation("Post", new PendingId("Post"));

        Assert.Equal(WriteOperation.Create, PendingWrite.Create("Post", mutation).Operation);
        Assert.Equal(WriteOperation.Update, PendingWrite.Update("Post", mutation).Operation);

        var delete = PendingWrite.Delete("Post");

        Assert.Equal(WriteOperation.Delete, delete.Operation);
        Assert.Null(delete.Mutation);
        Assert.Empty(delete.Actions);
        Assert.Empty(delete.Arguments);
        Assert.Null(delete.Action);
    }

    [Fact]
    public async Task No_policies_allows_everything_and_says_it_is_empty()
    {
        var none = new NoPolicies();

        Assert.True(none.IsEmpty());
        Assert.Equal(PolicyOutcome.Allow, (await ((IEntityReadPolicies)none).DecideAsync(new object(), new AnonymousViewer(), TestContext.Current.CancellationToken)).Outcome);
        Assert.Equal(
            PolicyOutcome.Allow,
            (await ((IEntityWritePolicies)none).DecideAsync(null, PendingWrite.Delete("Post"), new AnonymousViewer(), TestContext.Current.CancellationToken)).Outcome);
    }

    [Fact]
    public async Task The_anonymous_viewer_is_nobody_and_can_do_nothing()
    {
        var viewer = new AnonymousViewerProvider().Viewer();

        Assert.Null(viewer.Id);
        Assert.False(viewer.IsAuthenticated);
        Assert.False(viewer.HasRole("admin"));
        Assert.False(viewer.Can("edit"));
    }

    [Fact]
    public async Task A_decision_carries_its_outcome_reason_and_policy()
    {
        Assert.Equal(PolicyOutcome.Allow, PolicyDecision.Allow().Outcome);
        Assert.Equal(PolicyOutcome.Skip, PolicyDecision.Skip().Outcome);

        var decision = PolicyDecision.Deny("No.").WithPolicy("owner");

        Assert.Equal(PolicyOutcome.Deny, decision.Outcome);
        Assert.Equal("No.", decision.Reason);
        Assert.Equal("owner", decision.Policy);
        Assert.Null(PolicyDecision.Deny("No.").Policy);
    }

    [Fact]
    public async Task Outcomes_and_operations_round_trip_through_their_wire_names()
    {
        foreach (var outcome in Enum.GetValues<PolicyOutcome>())
        {
            Assert.Equal(outcome, PolicyOutcomeExtensions.FromWire(outcome.ToWire()));
        }

        foreach (var operation in Enum.GetValues<WriteOperation>())
        {
            Assert.Equal(operation, WriteOperationExtensions.FromWire(operation.ToWire()));
        }

        Assert.Throws<ArgumentException>(() => PolicyOutcomeExtensions.FromWire("maybe"));
        Assert.Throws<ArgumentException>(() => WriteOperationExtensions.FromWire("rename"));
    }
}
