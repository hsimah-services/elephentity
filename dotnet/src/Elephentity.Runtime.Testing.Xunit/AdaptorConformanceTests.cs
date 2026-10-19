using Elephentity.Runtime.Storage;
using global::Xunit;

namespace Elephentity.Runtime.Testing.Xunit;

/// <summary>
/// Turns the storage adaptor conformance suite into an xUnit theory.
/// </summary>
/// <remarks>
/// Inherit it, implement <see cref="CreateAdaptor" />, and every case in <see cref="AdaptorConformance" /> runs
/// as a test named for the promise it checks:
/// <code>
/// public sealed class MyAdaptorConformance : AdaptorConformanceTests
/// {
///     protected override IStorageAdaptor CreateAdaptor() =&gt; new MyAdaptor();
/// }
/// </code>
/// A new adaptor is made for each case, so a case that fails cannot leave rows behind for the next.
/// </remarks>
public abstract class AdaptorConformanceTests
{
    /// <summary>The case names, for the theory.</summary>
    public static TheoryData<string> Cases { get; } = [.. AdaptorConformance.CaseNames];

    /// <summary>The entities and edge the cases write to. Override when the backend needs them to exist already.</summary>
    protected virtual ConformanceNames Names => ConformanceNames.Default;

    /// <summary>A new, empty adaptor.</summary>
    /// <returns>The adaptor under test.</returns>
    protected abstract IStorageAdaptor CreateAdaptor();

    /// <summary>Runs one conformance case.</summary>
    /// <param name="name">The case's name.</param>
    /// <returns>A task that completes when the case has run.</returns>
    [Theory]
    [MemberData(nameof(Cases))]
    public async Task ConformsTo(string name)
    {
        var conformance = AdaptorConformance.Cases(Names).Single(candidate => candidate.Name == name);

        await conformance.Run(CreateAdaptor(), TestContext.Current.CancellationToken);
    }
}
