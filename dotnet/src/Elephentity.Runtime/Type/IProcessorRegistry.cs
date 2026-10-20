namespace Elephentity.Runtime.Type;

/// <summary>
/// Resolves the processors a declared type needs.
/// </summary>
/// <remarks>
/// Types are named in the spec and processors are supplied by the application, so the two meet
/// here. A type declaring <c>processors: true</c> with nothing implementing them is a boot failure,
/// not a run-time surprise.
/// </remarks>
public interface IProcessorRegistry
{
    /// <summary>Whether the application supplies processors for <paramref name="type" />.</summary>
    /// <param name="type">The declared type's name.</param>
    /// <returns><see langword="true" /> when it does.</returns>
    bool Has(string type);

    /// <summary>The read processor for <paramref name="type" />.</summary>
    /// <param name="type">The declared type's name.</param>
    /// <returns>The processor.</returns>
    IReadProcessor Read(string type);

    /// <summary>The write processor for <paramref name="type" />.</summary>
    /// <param name="type">The declared type's name.</param>
    /// <returns>The processor.</returns>
    IWriteProcessor Write(string type);
}
