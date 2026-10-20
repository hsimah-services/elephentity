namespace Elephentity.Runtime.Policy;

/// <summary>A provider whose viewer is always anonymous, for code that runs outside a request.</summary>
public sealed class AnonymousViewerProvider : IViewerProvider
{
    /// <inheritdoc />
    public IViewer Viewer() => new AnonymousViewer();
}
