using Elephentity.Runtime.Type;

namespace Elephentity.Runtime.Tests.Types;

public sealed class NullProcessorRegistryTests
{
    [Fact]
    public void It_has_no_type()
    {
        var registry = new NullProcessorRegistry();

        Assert.False(registry.Has("Money"));
    }

    [Fact]
    public void Reading_refuses_by_name()
    {
        var registry = new NullProcessorRegistry();

        var failure = Assert.Throws<InvalidOperationException>(() => registry.Read("Money"));

        Assert.Equal("Money declares no read processor.", failure.Message);
    }

    [Fact]
    public void Writing_refuses_by_name()
    {
        var registry = new NullProcessorRegistry();

        var failure = Assert.Throws<InvalidOperationException>(() => registry.Write("Money"));

        Assert.Equal("Money declares no write processor.", failure.Message);
    }
}
