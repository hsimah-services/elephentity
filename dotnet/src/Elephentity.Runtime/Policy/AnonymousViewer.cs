namespace Elephentity.Runtime.Policy;

/// <summary>The viewer nobody is signed in as: no id, no roles, no capabilities.</summary>
public sealed class AnonymousViewer : IViewer
{
    /// <inheritdoc />
    public string? Id => null;

    /// <inheritdoc />
    public bool IsAuthenticated => false;

    /// <inheritdoc />
    public bool HasRole(string role) => false;

    /// <inheritdoc />
    public bool Can(string capability) => false;
}
