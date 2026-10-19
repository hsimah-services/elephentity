using Elephentity.Runtime.Memory;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Testing.Xunit;

namespace Elephentity.Runtime.Memory.Tests;

/// <summary>The neutral driver against the same conformance suite every other adaptor has to pass.</summary>
public sealed class MemoryAdaptorConformanceTests : AdaptorConformanceTests
{
    protected override IStorageAdaptor CreateAdaptor() => new MemoryAdaptor();
}
