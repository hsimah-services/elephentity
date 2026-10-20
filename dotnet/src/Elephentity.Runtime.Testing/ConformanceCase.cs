using Elephentity.Runtime.Storage;

namespace Elephentity.Runtime.Testing;

/// <summary>
/// One thing a storage adaptor must do, as a function that throws when it does not.
/// </summary>
/// <remarks>
/// Framework-agnostic on purpose: a test framework turns the cases into tests by name
/// (<c>Elephentity.Runtime.Testing.Xunit</c> does it for xUnit), and a third-party adaptor can run them without
/// taking this repository's test framework. A case seeds only what it needs and removes it before returning, so
/// cases run in sequence against one adaptor never see one another's rows.
/// </remarks>
/// <param name="Name">A stable, readable name, such as <c>paging.take-zero-is-an-empty-terminal-page</c>.</param>
/// <param name="Run">Runs the case against an adaptor and throws <see cref="ConformanceFailureException" /> if it does not conform.</param>
public sealed record ConformanceCase(string Name, Func<IStorageAdaptor, CancellationToken, Task> Run);
