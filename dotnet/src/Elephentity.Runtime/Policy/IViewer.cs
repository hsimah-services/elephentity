namespace Elephentity.Runtime.Policy;

/// <summary>Who is asking: the identity a policy decides against.</summary>
public interface IViewer
{
    /// <summary>The viewer's identifier, or <see langword="null" /> when anonymous.</summary>
    string? Id { get; }

    /// <summary>Whether the viewer is signed in.</summary>
    bool IsAuthenticated { get; }

    /// <summary>Whether the viewer holds <paramref name="role" />.</summary>
    /// <param name="role">The role.</param>
    /// <returns><see langword="true" /> when it does.</returns>
    bool HasRole(string role);

    /// <summary>Whether the viewer may <paramref name="capability" />.</summary>
    /// <param name="capability">The application's own capability name.</param>
    /// <returns><see langword="true" /> when it may.</returns>
    bool Can(string capability);
}
