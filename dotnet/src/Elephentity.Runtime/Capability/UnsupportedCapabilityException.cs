namespace Elephentity.Runtime.Capability;

/// <summary>An adaptor was asked for something it does not support.</summary>
public sealed class UnsupportedCapabilityException : InvalidOperationException
{
    private UnsupportedCapabilityException(string message)
        : base(message)
    {
    }

    /// <summary>The failure for <paramref name="capability" /> on <paramref name="adaptor" />.</summary>
    /// <param name="capability">What was required.</param>
    /// <param name="adaptor">The adaptor that lacks it.</param>
    /// <returns>The exception, naming both.</returns>
    public static UnsupportedCapabilityException For(Capability capability, string adaptor) =>
        new($"The {adaptor} storage adaptor does not support {capability.ToWire()}.");
}
