using System.Reflection;

namespace Elephentity.Runtime.Memory.Tests;

public sealed class ScaffoldTests
{
    [Fact]
    public void The_package_assembly_is_built_and_referenced()
    {
        Assert.Equal("Elephentity.Runtime.Memory", Assembly.Load("Elephentity.Runtime.Memory").GetName().Name);
    }
}
