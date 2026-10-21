# Elephentity

An AI-native PHP framework that compiles human-readable specs into locked, signed
business logic. Read [docs/PLAN.md](docs/PLAN.md) first — it carries every design
decision and the reasoning behind it, and this file assumes it.
[docs/GLOSSARY.md](docs/GLOSSARY.md) defines the vocabulary, and
[docs/BUILDERS.md](docs/BUILDERS.md) is the contract a code generator implements.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, architecture, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
```

For the four gates against a real spec and real builders, see the examples in
[elephentity-examples](https://github.com/hsimah-services/elephentity-examples); it is
a separate repository now, not something this one can run against.

The compiler, `eleph`, is Rust, in `compiler/`. It runs in a container too, with no host cargo:

```bash
./tools/cargo cargo test --locked --manifest-path compiler/Cargo.toml
./tools/cargo cargo fmt --check --manifest-path compiler/Cargo.toml
./tools/cargo cargo clippy --locked --all-targets --manifest-path compiler/Cargo.toml -- -D warnings
tools/test-rust-ir                 # the built builders accept the IR the compiler produces
tools/test-package-install.sh      # a Composer project, end to end: runtime, builders and eleph
```

`compiler/tests/oracle/` holds what the retired PHP compiler produced for every spec in
`compiler/tests/specs/`. It is frozen: the compiler must keep reproducing it, and a
deliberate change to the output updates it by hand, with the reason in
[docs/divergence.md](docs/divergence.md).

`composer ci` must pass before committing. PHPStan runs at **level max** and there are
no baseline exclusions — the framework's whole claim is that generated code is provably
typed, so an exception here undermines the product.

## Layout

| Package | Ships? | Holds |
|---|---|---|
| `runtime/` | yes | storage port, unit of work, verification, loaders |
| `memory/` | yes | the neutral driver — no physical schema, no platform — and its builder, `bin/eleph-gen-memory` |
| `compiler/` (Cargo crate) | release binaries | the `eleph` command: spec parsing, the JSON Schemas (`compiler/resources/`), pattern resolution, the IR and its wire format |

The PHP compiler (`packages/schema`, `packages/cli`) was retired after the Rust one shipped at
parity in v0.14.0. Its last releases, and the `elephentity-schema`/`elephentity-cli` mirrors,
stop at v0.14.2.

**This repository has no platform adaptor, and WordPress and WPGraphQL are no longer supported.**
Their packages (`elephentity/wordpress`, `elephentity/wpgraphql`) and builders
(`elephentity/codegen-wordpress`, `elephentity/codegen-wpgraphql`) lived in their own repositories
and end at their last releases; do not add to them or depend on them.
`tools/check-architecture.php` still enforces that no package here references WordPress at all.
It uses the tokenizer, so prose in a doc comment is fine and a real call is not.

`packages/runtime` stays here — `memory` builds on it in-process — but is also mirrored, read-only, to
[`elephentity-runtime`](https://github.com/hsimah-services/elephentity-runtime) on
every push, so `elephentity/runtime` is installable on its own. Never edit that
mirror's `src/` or `tests/` directly; PRs go here.

**The code generator, the builders and the .NET packages are in this repository.** They used to
be separate repositories (`elephentity-codegen*`, `elephentity-dotnet`, `elephentity-graphql-php`,
`elephentity-sqlite`, `elephentity-patterns`); they were folded in without their history, and
each is its own unit with its own `README.md`, CI workflow and release. `eleph generate` pipes the IR
to `eleph-codegen` (`builders/codegen`), which resolves a project's builders and signs and writes what
they return; the root `composer.json` still installs `codegen` and `codegen-php` from Packagist as dev
dependencies so that `tools/test-rust-ir` can run.

| Directory | Is | Ships as | Tag |
|---|---|---|---|
| `compiler/` | the Rust `eleph` | GitHub Release binary | `v<version>` |
| `dotnet/` | the .NET runtime and adaptors | NuGet (`dotnet-v*`) | `dotnet-v<version>` |
| `builders/codegen` | `eleph-codegen`, the orchestrator | binary; Composer `elephentity/codegen` | binary `eleph-codegen-v<version>`, mirror `codegen-v<version>` |
| `builders/codegen-csharp`, `-cosmos`, `-graphql-csharp`, `-tsquid` | the C#, Cosmos, GraphQL.NET and tsquid builders | binary `eleph-gen-<name>`; tsquid also Composer | `eleph-gen-<name>-v<version>` |
| `builders/codegen-php`, `-sqlite`, `-graphql-php` | the PHP-side builders | Composer (mirrors) | `codegen-<name>-v<version>` |
| `packages/runtime`, `memory`, `sqlite`, `graphql` | the PHP package family: runtime, memory driver, SQLite and GraphQL adaptors | Composer (mirrors) | `php-v<version>`: one tag, one version, all four |
| `patterns/` | the pattern catalogue | — | — |

Each program is versioned independently, so a tag names the program it releases. The exception is
the PHP package family (runtime, memory, sqlite, graphql), which is released together under one
version; see [docs/RELEASING.md](docs/RELEASING.md#php-packages). Packagist cannot
publish several packages from one repository, so everything installed with Composer is mirrored,
read-only, to its old repository by `split.yml` (the mirror gets the plain `v<version>` tag). Never
edit a mirror; PRs go here. Workflows are path-filtered: `ci-<name>.yml`, `tests-<name>.yml`,
`release.yml` (binaries), `release-dotnet.yml` (NuGet).

`eleph install` reads a builder's release from this repository under its prefixed tag
(`compiler/src/cli/install.rs`, `tag_of`). `elephentity-examples` remains a separate repository.

**Worked examples live in their own repository.**
[`elephentity-examples`](https://github.com/hsimah-services/elephentity-examples) holds
`clog` (a minimal repro of the real application, still a WordPress plugin for now; WordPress is
being removed from it) and a `dotnet/` example, tested end to end against real builders and
adaptors — that is where a runtime class renamed out from under a builder actually gets caught,
not here.

## Conventions that are load bearing

- **The spec carries no application namespaces.** `handler: true`, `verify: true`,
  `processors: true` and policy declarations all mean "generate the interface"; names
  come from `eleph.json`.
- **Storage placement is inferred, never declared.** If the framework can work it out,
  a human choosing it is a chance for two entities to disagree.
- **Errors accumulate.** Compilers, verifiers and planners collect problems and report
  them together. Nothing aborts on the first failure unless continuing would be
  meaningless.
- **Generated code is grouped by entity.** `Item/` holds everything for Item, and
  `Item/Contract/` holds exactly what a consumer must implement.
- **One source of truth per decision.** `EdgePlanner` decides edge placement for both
  the schema builder and the query compiler. `Names` — now in the PHP builder — decides
  class names, and `eleph check` reads them out of the generated `class-map.php` rather
  than calling `Names` itself: the generator is a separate program and one day another
  language, so the tree carries its own index. The `targets` block of `eleph.json` has
  one parser too, and it is `eleph-codegen`'s; `eleph check` asks it rather than reading
  the file again. All three were bugs before they were rules.

## Testing

Pure logic is tested directly; anything touching a database or the filesystem is kept thin
enough that the thin part needs no test. `tools/test-package-install.sh` runs all four gates end to end
in a fresh Composer project, and is what proves the layers are wired together at all.

`compiler/tests/specs/valid/` is the canonical spec exercising every construct. Most
compiler tests compile it.

## Before you commit anything that crosses a repository boundary

The compiler, the code generator, the builders and the adaptors talk over a wire format, not a
shared classpath — so nothing type-checks across the gap, and a builder or adaptor that falls
behind the compiler fails at run time rather than at build time. Most of them share this
repository now, which lets one pull request change both sides, but it does not make the gap
type-check. `elephentity-examples` is still a separate repository.

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or with
the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape. Everything depends on
`dev-main`, so a contract change that lands alone breaks somebody's build that afternoon.

## Before you commit

- `./tools/php composer ci`
- If you changed anything a builder or adaptor reads — the IR, a runtime class it names,
  a wire shape — regenerate `clog` in
  [elephentity-examples](https://github.com/hsimah-services/elephentity-examples) and
  commit the diff there. If the change was to the generator itself, it is in another
  repository and `composer update` for the package that changed comes first
- If you made a decision the plan does not cover, add it to `docs/PLAN.md` — the
  reasoning is the artifact, not the code
