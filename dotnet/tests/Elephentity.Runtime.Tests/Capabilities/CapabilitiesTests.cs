using Elephentity.Runtime.Capability;

namespace Elephentity.Runtime.Tests.Capabilities;

public sealed class CapabilitiesTests
{
    [Fact]
    public void Requiring_an_unsupported_capability_names_the_adaptor()
    {
        var capabilities = new Capability.Capabilities("wordpress", Capability.Capability.Transactions);

        var failure = Assert.Throws<UnsupportedCapabilityException>(
            () => capabilities.Require(Capability.Capability.Faceting));

        Assert.Equal("The wordpress storage adaptor does not support faceting.", failure.Message);
    }

    [Fact]
    public void Requiring_a_supported_capability_is_silent()
    {
        var capabilities = new Capability.Capabilities("wordpress", Capability.Capability.Transactions);

        capabilities.Require(Capability.Capability.Transactions);

        Assert.True(capabilities.Supports(Capability.Capability.Transactions));
        Assert.False(capabilities.Supports(Capability.Capability.ForeignKeys));
    }

    [Fact]
    public void Capabilities_are_listed_in_declaration_order_of_the_enum()
    {
        var capabilities = new Capability.Capabilities(
            "wordpress",
            Capability.Capability.RowLocking,
            Capability.Capability.Transactions);

        Assert.Equal(
            [Capability.Capability.Transactions, Capability.Capability.RowLocking],
            capabilities.All());
    }

    [Fact]
    public void Every_capability_round_trips_through_its_wire_name()
    {
        foreach (var capability in Enum.GetValues<Capability.Capability>())
        {
            Assert.Equal(capability, CapabilityExtensions.FromWire(capability.ToWire()));
        }

        Assert.Equal("fullTextSearch", Capability.Capability.FullTextSearch.ToWire());
        Assert.Throws<ArgumentException>(() => CapabilityExtensions.FromWire("nothing"));
    }
}
