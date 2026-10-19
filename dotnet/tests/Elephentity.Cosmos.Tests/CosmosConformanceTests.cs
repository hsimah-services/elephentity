using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Testing.Xunit;

namespace Elephentity.Cosmos.Tests;

/// <summary>The Cosmos adaptor against the same conformance suite the memory driver passes.</summary>
/// <remarks>Needs the emulator or an account (see <see cref="CosmosTestEnvironment" />); skipped without one.</remarks>
public sealed class CosmosConformanceTests : AdaptorConformanceTests
{
    protected override IStorageAdaptor CreateAdaptor()
    {
        var environment = CosmosTestEnvironment.Require();

        // A partition of its own is an empty adaptor, so each case starts from nothing.
        return environment.Adaptor(Layouts.Conformance($"c-{Guid.NewGuid():N}"));
    }
}
