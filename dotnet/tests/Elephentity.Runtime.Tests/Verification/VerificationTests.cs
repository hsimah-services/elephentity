using Elephentity.Runtime.Verification;

namespace Elephentity.Runtime.Tests.Verification;

public sealed class VerificationTests
{
    [Fact]
    public void An_ok_result_has_no_violations()
    {
        Assert.True(Runtime.Verification.Verification.Ok().IsOk);
        Assert.Empty(Runtime.Verification.Verification.Ok().Violations);
    }

    [Fact]
    public void A_failure_needs_at_least_one_violation()
    {
        Assert.Throws<ArgumentException>(() => Runtime.Verification.Verification.Failed());
    }

    [Fact]
    public void Merging_preserves_violation_order()
    {
        var first = Runtime.Verification.Verification.Failed(new Violation("a", "First."));
        var second = Runtime.Verification.Verification.Failed(new Violation("b", "Second."));

        var merged = first.Merge(second);

        Assert.False(merged.IsOk);
        Assert.Equal(["a", "b"], merged.Violations.Select(v => v.Code));
    }

    [Fact]
    public void Merging_with_an_ok_result_changes_nothing()
    {
        var failed = Runtime.Verification.Verification.Failed(new Violation("a", "First."));

        Assert.Same(failed, failed.Merge(Runtime.Verification.Verification.Ok()));
        Assert.Same(failed, Runtime.Verification.Verification.Ok().Merge(failed));
        Assert.True(Runtime.Verification.Verification.Ok().Merge(Runtime.Verification.Verification.Ok()).IsOk);
    }
}
