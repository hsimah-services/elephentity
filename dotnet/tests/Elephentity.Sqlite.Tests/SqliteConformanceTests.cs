using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Testing.Xunit;

namespace Elephentity.Sqlite.Tests;

/// <summary>The SQLite adaptor against the same conformance suite the memory driver passes, on a database of its own per case.</summary>
public sealed class SqliteConformanceTests : AdaptorConformanceTests
{
    protected override IStorageAdaptor CreateAdaptor()
    {
        var manifest = Schema.Conformance();
        var connection = Schema.InstalledAsync(manifest).GetAwaiter().GetResult();

        return new SqliteAdaptor(connection, manifest);
    }
}
