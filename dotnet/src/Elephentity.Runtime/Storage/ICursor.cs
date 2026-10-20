namespace Elephentity.Runtime.Storage;

/// <summary>
/// An opaque position in a result set.
/// </summary>
/// <remarks>
/// Opaque on purpose: the encoding is the adaptor's business, and a client that parses a cursor
/// becomes coupled to the storage backend's ordering strategy. Callers pass one back exactly as
/// they received it. Opaque is not secret: a cursor is encoded, not encrypted, and a
/// <see cref="KeysetCursor" /> carries the last row's sort values, so order only by fields the
/// viewer may read.
/// </remarks>
public interface ICursor
{
    /// <summary>The cursor as a string, to hand to a client and take back.</summary>
    string Token { get; }
}
