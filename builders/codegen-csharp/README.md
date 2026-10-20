# elephentity-codegen-csharp

The C# builder for [Elephentity](https://github.com/hsimah-services/elephentity): the compiled spec in,
C# source out.

It turns the canonical spec into C# that compiles against [`elephentity-dotnet`](https://github.com/hsimah-services/elephentity/tree/main/dotnet)
with no warnings, uses no reflection, and runs on its memory driver. Implemented in Rust, like every Elephentity builder.

It reads one JSON request on stdin and writes one JSON response on stdout, and never touches the
filesystem: it returns paths and bodies, and signing and writing happen in
[elephentity-codegen](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen) after it exits.

## Installing it

No PHP or Composer is needed. Pin a release in `eleph.json`, then fetch it:

```bash
eleph install            # downloads eleph-gen-csharp@0.1.0 into tools/builders/
eleph install --check    # fails if it is missing, another version, or modified
```

From this repository: `cargo install --path . --locked`, or `cargo build --release --locked` and use
`bin/eleph-gen-csharp`, which picks `target/release` then `target/debug`. There is no Composer
package: the .NET path needs no PHP, so releases are the only distribution.

## Configuring it

```json
{
  "spec": "spec",
  "codegen": "eleph-codegen@0.2.0",
  "targets": {
    "csharp": {
      "builder": "eleph-gen-csharp@0.1.0",
      "output": "Generated",
      "namespace": "Hsimah.Entity",
      "typeNamespace": "Hsimah.Type",
      "check": ["dotnet", "run", "--project", "tests/Contracts", "--", "eleph-check"]
    }
  }
}
```

| Key | Required | |
|---|---|---|
| `builder` | yes | The program; `name@version` pins a release for `eleph install`. |
| `output` | yes | Where the tree is written. Its own directory, relative to the project. |
| `namespace` | yes | The C# namespace of the generated entities: dot-separated identifiers, none a reserved word. |
| `typeNamespace` | yes | The C# namespace of the declared types. |
| `check` | no | The command that runs the contract check, as an array. |

Every problem with the configuration is reported at once, as errors in a normal response, and no file
is written.

### `check`

Only the project knows where its contract-test project is, so it says so here, and the builder echoes
the command back in `describe` as `provides.check`. `eleph check` then runs it, with the project as the
working directory, and reads one `{"severity", "message"}` JSON object per line of its output
(`ContractCheck.TryRunConsole` in `Elephentity.Runtime.Testing` prints exactly that for `-- eleph-check`).
A target with no `check` is reported by `eleph check` as having no checker; it never passes silently.

## What it writes

One tree per target, flat namespaces under `namespace`:

| Namespace | Holds |
|---|---|
| `{ns}` | per entity: the read model `X` (with edge methods), `XHydrator`, `XInput`, `XMutator`, `XFinder`, the contexts, `XDeleter`, and the `XReadPolicies`/`XWritePolicies`/`XVerifiers`/`XSideEffects` dispatchers; `Catalogue`, `Processors`, `Wiring`, `ClassMap` |
| `{ns}.Contract` | exactly what the application must implement: `I<Entity><Name>Query`, `…Action`, `…SideEffect`, `…Verifier`, `…ReadPolicy`, `…WritePolicy` |
| `{ns}.Enums` | each enum and its `<Enum>Wire` encoder |
| `{ns}.Types` | `I<Type>ReadProcessor` / `I<Type>WriteProcessor` for declared types with processors |
| `{ns}.Pattern`, `{ns}.Pattern.Contract` | one interface per pattern, and its policy contracts |

`Add<Project>Entities(this IServiceCollection)` in `Wiring.cs` registers everything generated and leaves
the contracts to the application; `Catalogue.Contracts()` lists them, which is what `ContractCheck` and
`eleph check` read. `ClassMap.cs` is the tree's own index (C# has no `class-map.json` with a header style).

Decisions worth knowing: the spec `int` is `long`, `datetime` is `DateTimeOffset`, `json` is `JsonElement`;
declared types are assumed to be reference types and the application supplies them in `typeNamespace`; two
names that collide after C# casing are reported as errors and no file is written.

## Working on it

```bash
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked                 # includes the frozen golden trees
ELEPH_REFRESH=1 cargo test --locked every_golden   # rewrite tests/fixtures/golden/*/expected
echo '{"elephentity":1,"irVersion":"1.2","request":"describe","target":"csharp"}' | cargo run -q
```

- `tests/dotnet` compiles every golden tree against `elephentity-dotnet` at the commit in
  `tests/dotnet/elephentity-dotnet.sha` and runs the canonical one on the memory driver (create, read,
  update, delete, an action, a gated read, a paged query, verifiers, side effects, `ContractCheck`):

  ```bash
  cargo build && python3 tests/dotnet/materialize.py
  ELEPH_DOTNET_SOURCE=../elephentity-dotnet ./tools/dotnet dotnet run --project tests/dotnet -c Release
  ```

  Bump the sha in the same PR as any change to a runtime name in `rust/runtime.rs`.
- `rust/ir.rs` is a byte-identical copy of the PHP builder's, recorded in [SOURCE.md](SOURCE.md) and
  checked by `tests/ir_copy.rs`.
- `tests/protocol.rs` runs the real binary against the five request fixtures in `tests/fixtures/golden`,
  copied from `elephentity-codegen-php`.
- The version gate is the envelope (`1`) and the IR (`1.2`), declared in `rust/main.rs` and again in every
  other program. A mismatch is refused in both directions.

## Releasing

Releasing is the owner's call: an annotated `v*` tag runs `release.yml`, which uses
`elephentity-codegen`'s reusable workflow to build and publish four archives and `SHA256SUMS`
(`docs/RELEASING.md` there is the contract `eleph install` relies on).

MIT licensed.
