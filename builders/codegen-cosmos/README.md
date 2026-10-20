# elephentity-codegen-cosmos

The Azure Cosmos DB storage builder for [Elephentity](https://github.com/hsimah-services/elephentity): the compiled
spec in, the C# storage manifest [`Elephentity.Cosmos`](https://github.com/hsimah-services/elephentity/tree/main/dotnet) loads out.
It provides the `cosmos` storage driver. Implemented in Rust, like every Elephentity builder.

It reads one JSON request on stdin and writes one JSON response on stdout, and never touches the filesystem: it
returns paths and bodies, and signing and writing happen in
[elephentity-codegen](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen) after it exits.

## Installing it

No PHP or Composer is needed. Pin a release in `eleph.json`, then fetch it:

```bash
eleph install            # downloads eleph-gen-cosmos@0.1.0 into tools/builders/
eleph install --check    # fails if it is missing, another version, or modified
```

From this repository: `cargo install --path . --locked`, or `cargo build --release --locked` and use
`bin/eleph-gen-cosmos`, which picks `target/release` then `target/debug`.

## Configuring it

`project.yml` says `storage: {driver: cosmos}`; `eleph.json` adds the target next to the C# one:

```json
{
  "targets": {
    "cosmos": {
      "builder": "eleph-gen-cosmos@0.1.0",
      "output": "Generated/Storage",
      "namespace": "Hsimah.Storage"
    }
  }
}
```

| Key | Required | |
|---|---|---|
| `builder` | yes | The program; `name@version` pins a release for `eleph install`. |
| `output` | yes | Where the file is written. Its own directory, relative to the project. |
| `namespace` | yes | The C# namespace of the manifest factory: dot-separated identifiers, none a reserved word. |

**Deployment names are not configuration here.** Which account, database and container to use is the application's
configuration at run time, passed to `CosmosAdaptor` and to `CosmosStorageManifestFactory.Create(container)`. A project whose
`driver` is not `cosmos` gets an error, not an empty file. Every problem is reported at once, and no file is written.

## What it writes

One file, `CosmosStorageManifest.cs`: `CosmosStorageManifestFactory.Create(container = "elephentity")` returns the
`CosmosStorageManifest` for the project, with:

- **a partition value** (the project's name): one logical partition per project;
- **a layout per entity**: each field's property (a name the container reserves, `id`, `pk`, `type` or anything starting
  with `_`, gets a suffix: `type` is stored as `type_`, `_x` as `f_x`);
- **edge placements, inferred from the IR and never declared**: a to-one edge keeps its reference in `<edge>Id` on the
  entity itself, a one-to-many keeps `<inverse>Id` on the target, a many-to-many uses join documents;
- **unique keys** for `unique` fields, which the adaptor maps onto the container's slots `_u0…`;
- **composite indexes** for every `indexed` or `unique` field, ascending and descending. The spec declares no orderings,
  so a field marked `indexed` is the one a query may sort by; Cosmos refuses an `ORDER BY` without a composite index,
  so **ordering by a field that is not `indexed` fails at run time**.

Errors the builder reports (all at once): an entity with more unique fields than a container has unique keys (**10**; each
slot is one path, and every entity shares the container's keys; Azure Cosmos DB service quotas), two fields or an edge
reference landing in the same property, an entity on another driver or asking for platform-owned storage, a property
name that is not letters, digits and underscores. A field type the IR does not define is refused when the IR is read.

## Working on it

```bash
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked                  # includes the frozen manifests
ELEPH_REFRESH=1 cargo test --locked every_fixture   # rewrite tests/fixtures/*/expected
```

- `tests/fixtures/<name>/request.json` are the SQLite builder's fixtures with the driver changed to `cosmos` (the four
  relation shapes also mark `rank` as `indexed`, which the conformance cases sort by).
- `tests/dotnet` loads each generated manifest into `Elephentity.Cosmos` at the commit in
  `tests/dotnet/elephentity-dotnet.sha`, installs it into the emulator and runs the conformance cases:

  ```bash
  cargo build && python3 tests/dotnet/materialize.py
  ELEPH_DOTNET_SOURCE=../elephentity-dotnet ELEPH_COSMOS_ENDPOINT=https://localhost:8081 \
      ./tools/dotnet dotnet run --project tests/dotnet -c Release
  ```

  Without `ELEPH_COSMOS_ENDPOINT` the emulator tests skip; CI always sets it. Bump the sha in the same PR as any change
  to a manifest type in `Elephentity.Cosmos`.
- `rust/ir.rs` is a byte-identical copy of the PHP builder's, recorded in [SOURCE.md](SOURCE.md) and checked by
  `tests/ir_copy.rs`.
- The version gate is the envelope (`1`) and the IR (`1.2`), declared in `rust/main.rs` and again in every other program.

## Releasing

Releasing is the owner's call: an annotated `v*` tag runs `release.yml`, which uses `elephentity-codegen`'s reusable
workflow to build and publish four archives and `SHA256SUMS`.

MIT licensed.
