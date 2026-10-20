# elephentity-codegen-graphql-csharp

The GraphQL builder for the .NET stack of [Elephentity](https://github.com/hsimah-services/elephentity): the compiled spec in, the
manifest [`Elephentity.GraphQL`](https://github.com/hsimah-services/elephentity/tree/main/dotnet) assembles into a GraphQL.NET schema out.
Implemented in Rust, like every Elephentity builder.

**Its proof is SDL parity with the PHP stack.** The schema `Elephentity.GraphQL` builds from this manifest prints the same SDL,
byte for byte, as the schema `elephentity-graphql-php` builds from `eleph-gen-graphql-php`'s manifest for the same spec, so a client's
committed `schema.graphql` and tsquid's Relay compile do not care which server they talk to. The naming rules are the PHP builder's,
ported rule for rule.

It reads one JSON request on stdin and writes one JSON response on stdout, and never touches the filesystem: it returns paths and
bodies, and signing and writing happen in
[elephentity-codegen](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen) after it exits.

## Installing it

No PHP or Composer is needed. Pin a release in `eleph.json`, then fetch it:

```bash
eleph install            # downloads eleph-gen-graphql-csharp@0.1.0 into tools/builders/
eleph install --check    # fails if it is missing, another version, or modified
```

From this repository: `cargo install --path . --locked`, or `cargo build --release --locked` and use
`bin/eleph-gen-graphql-csharp`, which picks `target/release` then `target/debug`.

## Configuring it

The spec opts in exactly as it does for PHP: **the same integration key, `graphql`**, in `project.yml`, on each entity (`singular`,
`plural`) and on each query (`field`). A spec stays language-neutral.

```json
{
  "targets": {
    "csharp": {"builder": "eleph-gen-csharp@0.1.0", "output": "Generated/Entities", "namespace": "Hsimah.Entity", "typeNamespace": "Hsimah.Type"},
    "graphql": {
      "builder": "eleph-gen-graphql-csharp@0.1.0",
      "output": "Generated/GraphQL",
      "namespace": "Hsimah.GraphQL",
      "entityNamespace": "Hsimah.Entity"
    }
  }
}
```

| Key | Required | |
|---|---|---|
| `builder` | yes | The program; `name@version` pins a release for `eleph install`. |
| `output` | yes | Where the file is written. Its own directory, relative to the project. |
| `namespace` | yes | The C# namespace of the manifest factory. |
| `entityNamespace` | yes | The C# namespace of the entities: **it must be the `csharp` target's `namespace`**, because the manifest reads the generated read models. |

## What it writes

One file, `GraphQLManifest.cs`: `GraphQLManifestFactory.Create()` returns the `GraphQLManifest`. Accessors are delegates onto the
generated read model (`source => ((Post)source).Title`), so the C# compiler checks every one against `eleph-gen-csharp`'s output and
nothing uses reflection. An enum is read through its generated `<Enum>Wire` class (the GraphQL values are the stored values), a to-one
edge through `Get<Edge>Async`, a to-many edge through `<Edge>()`.

```csharp
services.AddPostsEntities();                          // eleph-gen-csharp
services.AddElephentityGraphQL(GraphQLManifestFactory.Create());   // this
app.MapElephentityGraphQL("/graphql");
```

Every problem is reported at once, with the configuration's: a type-name collision (including the generated `*Connection`,
`*Edge`, `*WhereArgs`, `*Input` and `*Payload` names), a field called `id`, `databaseId` or `clientMutationId`, an enum with no
values or labels that upper-case alike, a published query returning an entity that is not exposed.

## Working on it

```bash
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked                  # includes the frozen manifests
ELEPH_REFRESH=1 cargo test --locked every_fixture   # rewrite tests/fixtures/*/expected
```

- `tests/dotnet` compiles each generated manifest with the entities `eleph-gen-csharp` makes from the same IR, at the commits in
  `tests/dotnet/elephentity-dotnet.sha` and `elephentity-codegen-csharp.sha`, assembles the schema on the memory driver, executes it, and
  requires the SDL to equal `tests/parity/<fixture>.graphql`:

  ```bash
  cargo build && ELEPH_GEN_CSHARP=../elephentity-codegen-csharp/target/debug/eleph-gen-csharp python3 tests/dotnet/materialize.py
  ELEPH_DOTNET_SOURCE=../elephentity-dotnet ./tools/dotnet dotnet run --project tests/dotnet -c Release
  ```
- `tests/parity/*.graphql` are printed from the **PHP** stack: `tools/refresh-parity` runs `eleph-gen-graphql-php`, assembles the schema
  with `elephentity-graphql-php` on webonyx in the PHP container, and normalises it with graphql-js (`printSchema` over
  `lexicographicSortSchema`, which is what `tsquid-schema fetch` writes). Refresh them when a fixture or the PHP stack changes; a
  difference is a bug in one of the two stacks, and the test says which line.
- [docs/divergence.md](docs/divergence.md) lists where this builder differs from the PHP one beyond what the SDL shows.
- `rust/ir.rs` is a byte-identical copy of the PHP builder's, recorded in [SOURCE.md](SOURCE.md) and checked by `tests/ir_copy.rs`.

## Releasing

Releasing is the owner's call: an annotated `v*` tag runs `release.yml`, which uses `elephentity-codegen`'s reusable workflow to build
and publish four archives and `SHA256SUMS`.

MIT licensed.
