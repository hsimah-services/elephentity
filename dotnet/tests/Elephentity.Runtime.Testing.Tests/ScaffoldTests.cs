using System.Reflection;

namespace Elephentity.Runtime.Testing.Tests;

public sealed class ScaffoldTests
{
    [Fact]
    public void The_package_assembly_is_built_and_referenced()
    {
        Assert.Equal("Elephentity.Runtime.Testing", Assembly.Load("Elephentity.Runtime.Testing").GetName().Name);
    }
}
