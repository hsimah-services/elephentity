namespace Elephentity.Runtime.Boot;

/// <summary>
/// Raised at boot when generated interfaces have no implementations.
/// </summary>
/// <remarks>
/// The spec declares the bespoke unit, the generator emits its interface, and the application supplies the
/// class. Until it does, the application does not start. Boot time rather than call time, so a missing handler
/// cannot lurk in production until the wrong request arrives; and boot rather than generate time, so codegen
/// stays a pure function of the spec. Every missing implementation is listed at once.
/// </remarks>
public sealed class MissingImplementationsException : InvalidOperationException
{
    /// <summary>A failure naming <paramref name="interfaces" />.</summary>
    /// <param name="interfaces">The generated interfaces nothing implements.</param>
    public MissingImplementationsException(IReadOnlyList<System.Type> interfaces)
        : base(
            $"Elephentity cannot start: {interfaces.Count} generated interface(s) have no implementation.\n  "
            + string.Join("\n  ", interfaces.Select(contract => contract.FullName)))
    {
        Interfaces = interfaces;
    }

    /// <summary>The interfaces with no implementation.</summary>
    public IReadOnlyList<System.Type> Interfaces { get; }
}
