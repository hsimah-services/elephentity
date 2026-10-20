namespace Elephentity.Runtime.Policy;

/// <summary>Supplies the viewer for the current request.</summary>
public interface IViewerProvider
{
    /// <summary>The viewer asking now.</summary>
    /// <returns>The viewer.</returns>
    IViewer Viewer();
}
