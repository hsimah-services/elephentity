using System.Reflection;

namespace Elephentity.Runtime.Tests;

public sealed class ScaffoldTests
{
    [Fact]
    public void The_package_assembly_is_built_and_referenced()
    {
        Assert.Equal("Elephentity.Runtime", Assembly.Load("Elephentity.Runtime").GetName().Name);
    }
}
