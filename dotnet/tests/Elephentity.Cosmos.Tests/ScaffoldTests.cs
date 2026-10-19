using System.Reflection;

namespace Elephentity.Cosmos.Tests;

public sealed class ScaffoldTests
{
    [Fact]
    public void The_package_assembly_is_built_and_referenced()
    {
        Assert.Equal("Elephentity.Cosmos", Assembly.Load("Elephentity.Cosmos").GetName().Name);
    }
}
