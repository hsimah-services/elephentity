namespace Elephentity.Runtime.Type;

/// <summary>
/// The registry a project with no <c>processors: true</c> types needs.
/// </summary>
/// <remarks>
/// The unit of work takes a <see cref="IProcessorRegistry" /> unconditionally, because it asks about
/// every declared type without knowing in advance that a given project declares none. Without this,
/// a project with zero such types still has to write an implementation before it can construct one,
/// and the obvious wrong one, returning the value it was given, would silently skip the
/// verification a declared type exists to perform. <see cref="Has" /> always answering false keeps
/// that mistake from happening: nothing that calls <see cref="Read" /> or <see cref="Write" /> does
/// so without checking <see cref="Has" /> first.
/// </remarks>
public sealed class NullProcessorRegistry : IProcessorRegistry
{
    /// <inheritdoc />
    public bool Has(string type) => false;

    /// <inheritdoc />
    public IReadProcessor Read(string type) =>
        throw new InvalidOperationException($"{type} declares no read processor.");

    /// <inheritdoc />
    public IWriteProcessor Write(string type) =>
        throw new InvalidOperationException($"{type} declares no write processor.");
}
