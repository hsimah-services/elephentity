# What an adaptor contains

## PHP (`packages/<name>/`)

| File | Holds |
|---|---|
| `composer.json` | Runtime dependencies and extensions, PSR-4 namespace. No compiler dependency. Joins the `php-v*` family: same version as `runtime`, `memory`, `sqlite`, `graphql` (`docs/RELEASING.md#php-packages`) |
| `src/Database.php` | Connection, parameter binding, transactions, savepoints |
| `src/<Name>Adaptor.php` | Implements `StorageAdaptor` |
| `src/Manifest/StorageManifest.php` | The constructor contract the emitted manifest satisfies |
| `src/Sql/Column.php`, `TableSchema.php`, `Index.php` | Physical metadata (keep dialect DDL separate) |
| `src/Sql/EdgePlacement.php` | Owner/target/join placement per edge |
| `src/Sql/FieldMap.php` | Spec field names ↔ physical names |
| `src/Sql/QueryCompiler.php` | Filters, order, pagination, edge queries, bindings |
| `tests/` | `AdaptorConformance` plus backend-specific constraints, rollback, concurrency |

Non-relational stores replace `Sql/` with their own layout objects; keep the `Manifest/`
idea (typed, emitted, loaded by path).

## C# (`dotnet/src/Elephentity.<Name>/`)

| File | Holds |
|---|---|
| `<Name>Adaptor.cs` | Implements `IStorageAdaptor`; no I/O in the constructor |
| `Manifest/<Name>StorageManifest.cs` | Constructor contract emitted by the builder |
| `<Name>Installer.cs` | Explicit installation; refuses a populated target |
| `<Name>Backup.cs` | Optional export/import into an empty target (migration) |
| `Query/`, `Writes/`, `Documents/`, `Serialization/` | Cosmos's split; mirror it for a similar store |
| `tests/Elephentity.<Name>.Tests` | xUnit v3; conformance plus store-specific cases |

Add the package to `Directory.Packages.props` (versions are decided once, there) and set
`PackageLicenseExpression` MIT. Every public member needs an XML doc comment (warnings are
errors). Differences from the PHP runtime go in `dotnet/docs/divergence.md` in the same PR.

## Builder side

`builders/codegen-<name>/rust/`: `provides.json`, `storage.rs` (the manifest emitter and
install DDL), plus the files in `eleph-add-builder/reference/anatomy.md`. The runtime type
names the builder emits are strings; a rename in the adaptor is invisible to its tests
until a generated project fails.
