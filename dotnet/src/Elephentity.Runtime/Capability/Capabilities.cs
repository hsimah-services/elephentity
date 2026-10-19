namespace Elephentity.Runtime.Capability;

/// <summary>
/// What one adaptor can do.
/// </summary>
public sealed class Capabilities
{
    private readonly HashSet<Capability> supported;

    /// <summary>Declares what the adaptor <paramref name="adaptor" /> supports.</summary>
    /// <param name="adaptor">The adaptor's name, used in messages.</param>
    /// <param name="capabilities">What it supports.</param>
    public Capabilities(string adaptor, params Capability[] capabilities)
    {
        Adaptor = adaptor;
        supported = [.. capabilities];
    }

    /// <summary>The adaptor's name.</summary>
    public string Adaptor { get; }

    /// <summary>Whether the adaptor supports <paramref name="capability" />.</summary>
    /// <param name="capability">The capability to ask about.</param>
    /// <returns><see langword="true" /> when it is supported.</returns>
    public bool Supports(Capability capability) => supported.Contains(capability);

    /// <summary>Fails unless the adaptor supports <paramref name="capability" />.</summary>
    /// <param name="capability">The capability to require.</param>
    /// <exception cref="UnsupportedCapabilityException">It is not supported.</exception>
    public void Require(Capability capability)
    {
        if (!Supports(capability))
        {
            throw UnsupportedCapabilityException.For(capability, Adaptor);
        }
    }

    /// <summary>Every supported capability, in the order the enum declares them.</summary>
    /// <returns>The supported capabilities.</returns>
    public IReadOnlyList<Capability> All() =>
        [.. Enum.GetValues<Capability>().Where(Supports)];
}
