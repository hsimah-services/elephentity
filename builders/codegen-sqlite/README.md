> **This is the source of truth.** `hsimah-services/elephentity-codegen-sqlite` is a read-only mirror of [`builders/codegen-sqlite/`](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen-sqlite) in [`hsimah-services/elephentity`](https://github.com/hsimah-services/elephentity), kept for Composer and Packagist. Open issues and pull requests on `elephentity`.

# elephentity-codegen-sqlite

Build-time SQLite generator for Elephentity, in PHP or C#. For PHP it emits a storage
manifest and an explicit initial schema installer for `elephentity/sqlite`; for C# it emits
a storage manifest, with the same schema, for [`Elephentity.Sqlite`](https://github.com/hsimah-services/elephentity/tree/main/dotnet).
The tables, columns, indexes, constraints and edge placements are worked out once and are the
same in both. No WordPress package is required.

## Install

```sh
composer require --dev elephentity/codegen-sqlite:dev-main
cargo build --release --locked --manifest-path vendor/elephentity/codegen-sqlite/Cargo.toml
```

Alternatively, run `cargo install --path . --locked` from a checkout and use
`eleph-gen-sqlite` on PATH. Composer provides a shell launcher; it does not compile
Rust automatically. Production needs only the runtime and generated PHP artifacts.

Configure the target in `eleph.json`, alongside your PHP entity target:

```json
{
  "spec": "spec",
  "targets": {
    "php": {
      "builder": "vendor/bin/eleph-gen-php",
      "output": "generated",
      "namespace": "App\\Entity",
      "typeNamespace": "App\\Type"
    },
    "sqlite": {
      "builder": "vendor/bin/eleph-gen-sqlite",
      "output": "generated/sqlite",
      "language": "php"
    }
  }
}
```

**`language` is required: `"php"` or `"csharp"`.** There is no default, so a project never gets
the wrong language's output by omission (a C# project that forgot the key would otherwise get
`storage-manifest.php` and no error). A project that configured this target before the key existed
adds `"language": "php"`. A `csharp` target also needs a `namespace`, below.

In `spec/project.yml`:

```yaml
project: App
storage:
  driver: sqlite
  tablePrefix: app_
```

`describe` advertises the `sqlite` driver, so the compiler discovers it without a
hardcoded driver addition. This builder speaks protocol 1 and IR 1.2. Use matching
compiler/orchestrator/PHP-builder versions; an older IR is rejected explicitly.

## C# (.NET)

The same builder emits C# for `Elephentity.Sqlite`. Set the target's `language` to `"csharp"` and give it a
`namespace`; there is no PHP or Composer in the loop, `eleph install` fetches the binary pinned as `name@version`:

```json
{
  "targets": {
    "sqlite": {
      "builder": "eleph-gen-sqlite@0.1.0",
      "output": "Generated/Sqlite",
      "language": "csharp",
      "namespace": "Hsimah.Storage"
    }
  }
}
```

| Key | Required | |
| --- | --- | --- |
| `builder` | yes | The program; `name@version` pins a release for `eleph install`. |
| `output` | yes | Where the file is written. Its own directory, relative to the project. |
| `language` | yes | `"php"` or `"csharp"`. |
| `namespace` | for `csharp` | The C# namespace of the manifest factory: dot-separated identifiers, none a reserved word. Ignored for `php`. |

It writes one file, `SqliteStorageManifest.cs`, whose `SqliteStorageManifestFactory.Create()` returns the
`SqliteStorageManifest` that `SqliteInstaller` and `SqliteAdaptor` take. The statements that create the schema travel inside
it, where PHP gets them as `install.php`:

```csharp
var manifest = SqliteStorageManifestFactory.Create();

await using var connection = new SqliteConnection("Data Source=/var/lib/app/data.sqlite");
await SqliteInstaller.InstallAsync(connection, manifest);   // explicit, once, on a fresh schema

using var storage = new SqliteAdaptor(connection, manifest);
```

An additional deployment prefix is passed to both, as in PHP: `InstallAsync(connection, manifest, prefix)` and
`manifest.WithPrefix(prefix)`. The validation (driver, identifiers, collisions, defaults) is shared with PHP; only the output
differs. `eleph generate --check` detects drift in either language. See
[`dotnet/docs/sqlite.md`](../../dotnet/docs/sqlite.md) for the adaptor.

## Generated artifacts (PHP)

| File | Purpose |
| --- | --- |
| `storage-manifest.php` | `Eleph\SQLite\Manifest\StorageManifest`: entity tables, field mappings, indexes, edge placements and join tables |
| `install.php` | A closure accepting PDO SQLite and an optional additional table prefix; atomically creates the initial schema |

The orchestrator signs and writes these files. The builder returns JSON and never
writes to the project directory itself.

```php
$database = new Eleph\SQLite\Database('/var/lib/app/data.sqlite');
$install = require __DIR__ . '/generated/sqlite/install.php';
$install($database->pdo); // Explicit installation command, once on a fresh schema.

$manifest = require __DIR__ . '/generated/sqlite/storage-manifest.php';
$storage = new Eleph\SQLite\SQLiteAdaptor(
    $database,
    $manifest->tables,
    new Eleph\SQLite\Sql\FieldMap($manifest->columns),
    $manifest->placements,
    new Eleph\SQLite\Sql\QueryCompiler(placements: $manifest->placements),
);
```

The compiler has already applied `project.storage.tablePrefix` to the IR table names.
If you need an **additional** deployment prefix, pass the same value to
`$install($database->pdo, $prefix)` and `$manifest->withPrefix($prefix)`.
The SQLite prototype's `Database::prefix()` returns `app_`; the adapter itself uses
the table names in the manifest, so do not automatically apply that prefix twice.

## Schema behavior

- Identity columns use `INTEGER PRIMARY KEY AUTOINCREMENT`.
- Scalars map to SQLite `TEXT`, `INTEGER` or `REAL`; JSON and dates are encoded by the runtime.
- Unique/indexed fields produce indexes. Boolean values, enum values and maximum lengths receive CHECK constraints.
- One-to-one, many-to-one, one-to-many and many-to-many edges produce matching placements and foreign keys. Join pairs are unique.
- Foreign keys use `ON DELETE RESTRICT`. The runtime owns cascade/nullify policy and authorization; direct SQL cannot bypass that work through a database cascade.
- Edge columns allow temporary NULL because the unit of work inserts entities before linking them. The runtime enforces required edges.
- Defaults and managed values are supplied by generated PHP/runtime writes, rather than duplicated as SQL defaults.
- Text uses SQLite's default comparison behavior. Clog's custom Unicode collation is not silently applied to other applications.

The installer (`install.php`, or `SqliteInstaller` in C#) rejects an existing table and rolls back all changes. It rejects an
existing transaction and enables foreign keys before beginning its own transaction.
It does not modify populated schemas or claim to generate upgrade migrations.
Keep reviewed migrations in the application. Existing SQLite runtime prototype
limitations remain; this package does not fix the core deletion-planner issue noted
in [elephentity#89](https://github.com/hsimah-services/elephentity/issues/89).

## Verify

```sh
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked                  # includes the frozen PHP responses and the frozen C# trees
ELEPH_REFRESH=1 cargo test --locked freezes_the_csharp_output   # rewrite tests/fixtures/csharp
composer --working-dir=tests install --no-interaction --no-plugins --no-scripts
php tests/runtime.php
```

PHP tests use the tagged SQLite/runtime packages, create disposable databases and
run the upstream adapter conformance harness for every relationship shape. They
also check constraints, identifier prefixes, NULL, literal escaping and rollback.

The C# is held to the same cases against a real SQLite, in `Elephentity.Sqlite` at this repository's `dotnet/` tree:

```sh
cargo build && python3 tests/dotnet/materialize.py
./tools/dotnet dotnet run --project tests/dotnet -c Release
```

`materialize.py` writes the manifest for every fixture, plus the constraint, default and integer-enum variants `tests/runtime.php`
builds, into `tests/dotnet/Generated` (not committed); the project compiles them, installs each into an in-memory database and
runs the conformance suite on every relation shape. A manifest type renamed in `Elephentity.Sqlite` fails here, at compile time.

[The standalone example](examples/standalone/eleph.json) combines PHP, SQLite and
GraphQL PHP generation. With `eleph` on PATH (`install.sh`, or set `ELEPH`), and in the
sibling-repository workspace with all three builders plus the orchestrator built and their
test dependencies installed (`composer install` in `elephentity-codegen-php` and in each
builder's `tests/`), run:

```sh
php tools/test-pipeline.php /path/to/elephentity-dev
```

This generates from YAML, checks signed output for drift, runs `eleph check` against
actual generated entities and installs the generated SQLite schema. The GraphQL
builder's test dependencies must also be installed for that workspace test.

See [SOURCE.md](SOURCE.md) for the source of reused protocol and fixture code.
