// Hand-written pieces the golden trees leave to the application: the declared types their specs name.
// Each tree is its own namespace, so each has its own.

namespace Golden.Valid.Declared
{
    public sealed record Money(long Minor);
}

namespace Golden.RuntimeContracts.Declared
{
    public sealed record Amount(long Minor);
}
