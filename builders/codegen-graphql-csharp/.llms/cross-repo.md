# The contract surface

Everything shared by the repositories in [README.md](README.md). A change to anything
on this list needs issues on the repositories named beside it; internal changes do not.

There are nine current protocol implementations across eight repositories:
`elephentity` (compiler client and memory builder), `elephentity-codegen` (orchestrator)
and six Rust builders: php, wordpress, wpgraphql, sqlite, graphql-php and tsquid.
The three planned C# builders bring this to twelve implementations across eleven
repositories; rewriting the compiler in Rust replaces its client rather than adding one.
Typed builders (php, wordpress, wpgraphql, sqlite, graphql-php) keep their live IR copy in
`rust/ir.rs`; the PHP `src/Ir/*` those repositories still ship is not the live copy. The
tsquid builder has no typed copy: it reads `schema.project.integrations` straight from
the request JSON in `rust/main.rs`.

Read the row for what you touched, not the whole table.

`elephentity-runtime` mirrors runtime source and tests, so source changes go to
`elephentity`. Its licence, Composer metadata and tooling remain locally owned.
The PHP runtime adaptors and integrations implement runtime interfaces and are named
as strings by their builders. `elephentity-dotnet` type-checks its runtime packages
together, but C# builders' `rust/runtime.rs` constants still cross a repository boundary.
`elephentity-examples` consumes generated output as the end-to-end check.

---

## Changing `elephentity`

| You changed | `elephentity-codegen` must | every builder repository must |
|---|---|---|
| Rust `IrCodec::VERSION` under `compiler/` (owns the wire version from step 3; PHP `packages/schema/src/Wire/IrCodec.php` is the oracle copy) | bump `Envelope::IR_VERSION` | bump its own `Envelope::IR_VERSION`; keep the PHP oracle version aligned |
| the shape of the compiler IR (`compiler/` from step 3; `packages/schema/src/Ir/*` is the PHP oracle) | — nothing; the IR is opaque to it | copy the change into its own `rust/ir.rs`, re-freeze golden fixtures |
| the compiler request (`GenerateCommand::request()`) | update `Protocol/CompilerRequest` | — |
| what `provides` may contain (`packages/cli/src/Installed.php`) | — nothing; `provides` is opaque to it | answer the new shape to `describe` (only the builder(s) the new shape concerns) |
| a class name or namespace under `packages/runtime/src/` | — | update the matching constant in its own `src/Runtime.php` (php builder: `rust/runtime.rs`), if it names that class |
| an interface under `packages/runtime/src/` that `elephentity-wordpress` or `elephentity-wpgraphql` implements (`StorageAdaptor`, `Verifier`, `Viewer`, …) | — | `elephentity-wordpress`/`elephentity-wpgraphql` must update the implementing class to match |
| `eleph.json` keys **it** reads (`spec`, `codegen`, `builders`; `codegen` may be pinned as `name@version`) | only if the key is also the orchestrator's (`builders` is) | — |

**Every builder repository** means php, wordpress, wpgraphql, sqlite, graphql-php
and tsquid, plus csharp, cosmos and graphql-csharp once scaffolded. Update each
typed `rust/ir.rs` copy that reads the changed fields and its version gate; for tsquid,
check the JSON paths `rust/main.rs` reads. The memory
builder reads no IR fields and only needs envelope-version changes.

**The IR is the one that bites.** The compiler IR is *copied* into each
builder's own `rust/ir.rs`, not shared. Nothing fails at build time when they diverge:
the compiler encodes a field a builder silently drops, and the generated code — or
compiled manifest — is quietly missing something. Only a version bump makes it loud,
which is why adding a field to the IR is worth bumping for even when an old builder
would technically still run.

**Renaming a runtime class is invisible to every test in every repository.** A builder
emits the name as a string; nothing in that repository loads it. It fails in a
*project*, at boot, after generating. Regenerating `clog` in `elephentity-examples` is
what catches it — and it only catches it if the class is one the example actually uses.

**`elephentity-runtime` only syncs `src/` and `tests/`, automatically, on every push
to `main`.** `packages/runtime/composer.json`'s own `require` block — `php`, `psr/log`,
`psr/container` — is not part of that sync. Adding or changing a real dependency there
needs the matching edit made by hand in `elephentity-runtime`'s own `composer.json`, or
that mirror silently falls behind what its own source actually needs to run. Everything
else on that repository — `composer.json`'s `require-dev`/`scripts`/`config`, its docs,
its CI, `tools/`, `.docker/` — is that repository's own outright, never touched by the
sync; an earlier version of the split workflow force-pushed all of it away once
(elephentity#79) precisely by not making this distinction.

---

## Changing `elephentity-codegen`

It owns the protocol, so its blast radius is the largest: **eight** other current
implementations — `elephentity`'s compiler client, its memory builder and six Rust
builders — plus the three C# builders once scaffolded.

| You changed | `elephentity` must | every builder repository must |
|---|---|---|
| `Envelope::VERSION` | update its request builder; update `eleph-gen-memory` | bump `Envelope::VERSION` |
| `Envelope::IR_VERSION` | keep `IrCodec::VERSION` equal | bump to match |
| the `describe` request or response | update `Installed::fromJson()`; update `eleph-gen-memory` | update its describe answer |
| the `generate` request or response | update `eleph-gen-memory` | update its generate answer, re-freeze golden fixtures |
| `Protocol/CompilerRequest` | update `GenerateCommand::request()` | — |
| the `targets` command's JSON | update `CheckCommand::outputDirectory()` | — |
| the `describe` command's JSON | update `Installed::fromJson()` | — |
| `Signing/HeaderStyle` — the rendered header or its line count | regenerate **every** tree and commit it | re-freeze golden fixtures |
| `Config/ProjectConfig` — which `eleph.json` keys are required | update the docs and `elephentity-examples`'s `clog/eleph.json` | — |
| a target's `builder` pin `name@version`, its `source` key, and `targets` reporting `source` | strip `@version` when looking a builder up (never from a path); report `builder` as written and `source`; do not pass `source` to builders | — (a builder never sees the pin or `source`) |
| the release artifact names (`release-binary.yml`, `docs/RELEASING.md`): tag `v<version>`, archives `<binary>-<version>-<rid>.tar.gz` (`.zip` on `win-x64`) holding `<binary>` and `LICENSE`, and one `SHA256SUMS` | `eleph install` finds and verifies archives by exactly these names | keep the released binary named as `eleph.json` pins it (`eleph-gen-csharp@0.1.0`) and tag `v<version>`; a repository adopting the workflow follows `docs/RELEASING.md` |

**A header change invalidates every signature ever written.** Not just here: every
generated file in every project using Elephentity fails verification at once, because the
digest covers the body and the header is excluded *by line count*. It is the most
expensive change available in this system. Say so in the issue title.

---

## Changing `elephentity-codegen-php`, `elephentity-codegen-wordpress` or `elephentity-codegen-wpgraphql`

The three builder repositories reach the rest of the ecosystem the same way; read the
row for the one you changed.

| You changed | `elephentity-examples` must | `elephentity-codegen` must |
|---|---|---|
| the bytes of any generated file | regenerate `clog` and commit the diff | — |
| the shape of `class-map.php` (php builder only) | — (`packages/cli/src/ClassMap.php` lives in `elephentity`; open an issue there too) | — |
| what it answers to `describe` | — (`Installed` lives in `elephentity`; open an issue there too) | — |
| `Envelope::IR_VERSION` or its `rust/ir.rs` | keep `IrCodec::VERSION` and `elephentity`'s compiler IR and PHP oracle in step | bump `Envelope::IR_VERSION` |
| its `src/Runtime.php` constants (php builder: `rust/runtime.rs`) | regenerate `clog` — a stale constant fails there, at boot | — (`elephentity-wordpress`/`elephentity-wpgraphql` own the classes named; keep them in step, or file there too) |
| which `eleph.json` target keys it needs (`PhpConfig`, or the target's own config reader) | update `clog/eleph.json` and the docs | — |
| a package pattern it ships (wordpress builder: `resources/patterns/*.yml`) | none, if the pattern's shape is unchanged; regenerate if it is | — |

**Generated-byte changes always reach `elephentity-examples`,** even the cosmetic ones.
Its committed `clog/generated/` tree is signed, so a whitespace change is a digest
change is a failing `generate --check` on the next `composer update`. There is no such
thing as a change in a builder that the example does not notice.

---

## Changing `elephentity-wordpress` or `elephentity-wpgraphql`

The two runtime adaptor repositories (elephentity#79). Neither speaks the wire protocol
itself — a builder names their classes as strings, not imports — so nothing here bumps
`Envelope::VERSION` or `IrCodec::VERSION`.

| You changed | the matching builder repository must | `elephentity-examples` must |
|---|---|---|
| a class name or namespace this package exports (`elephentity-wordpress`: anything under `Eleph\WordPress\`; `elephentity-wpgraphql`: `Eleph\WPGraphQL\`) | update the matching constant in its own `src/Runtime.php` — nothing fails there until a project boots on the old name | regenerate `clog` and confirm it still boots; this is the check that actually catches a missed update |
| a class implementing `Eleph\Runtime\Storage\StorageAdaptor`, `Conformance\Verifier`, `Policy\Viewer` or `Policy\ViewerProvider` | — | run the four gates; `eleph check` exercises the verifier for real |
| its own `composer.json` version constraint on `elephentity/runtime` | — | bump the matching constraint in `clog/composer.json` once a new version is tagged |

**A rename here is invisible to every test in this repository, in the matching builder
repository, and in `elephentity`.** It fails in a real project, at boot, when the
exported manifest or verifier names a class that no longer exists. Regenerating `clog`
in `elephentity-examples` is the only thing that actually runs that path.

---

## What is *not* on the surface

Listed because guessing wrong in this direction is the expensive one.

- **The IR passes through `elephentity-codegen` opaque.** Adding a field to an entity
  needs a new compiler and a new builder, and no release of the orchestrator.
- **`provides` passes through it opaque too**, in the other direction. A builder that
  starts providing a new kind of thing needs a new compiler, not a new orchestrator.
- **A target's `config` block** is read only by the builder that owns it. Adding a key to
  the `php` target reaches nothing but `elephentity-codegen-php`.
- **Anything a builder does internally.** How a builder decides a name, a table shape or
  a manifest's internal structure is its own business right up until the bytes change —
  at which point it is the first row of the builder table above, and it is the bytes
  that are the contract, not the decision.
- **One builder's `src/Runtime.php`, `rust/ir.rs` or `src/Protocol/*` copy is invisible to
  every other builder.** Each is independent; `elephentity-codegen-wordpress` falling
  behind the IR does not reach `elephentity-codegen-wpgraphql` or vice versa.

Schema, CLI and memory mirrors are entirely owned by `packages/<name>` here, including
Composer metadata, resources, bins and CI. Change those in the source repository;
the split workflow synchronizes them on main pushes and source tags. The memory
builder lives in the CLI package so memory has no compiler dependency at runtime.


## Rust compiler and target-delegated checks (steps 2–6)

The Rust `eleph` lives in `elephentity/compiler/`. From step 3 its `IrCodec::VERSION`
owns the wire version; `packages/schema/src/Wire/IrCodec.php` becomes the frozen
oracle copy. Changes to IR shape or encoding require matching builder `rust/ir.rs`
updates and parity verification through `tools/compare-compiler`. An IR-version bump
must merge with the orchestrator and every builder version gate together.
The Rust compiler request and the PHP migration-oracle request must agree with
`elephentity-codegen`'s `Protocol/CompilerRequest` contract.

| You changed | Repositories that must follow |
|---|---|
| `provides.check: {"command": [...]}` shape | `elephentity`'s Rust discovery/check dispatcher and PHP oracle while active; `elephentity-codegen-php`'s inline `provides` answer in `rust/main.rs` (step 6 may move it to a file) and every builder advertising a checker, including C# when it advertises one |
| `eleph-check-php` invocation or class-map/verifier input | `elephentity-codegen-php` checker; `elephentity` dispatcher; `elephentity-examples` check configuration |
| C# target-configured `ContractCheck` command/input | `elephentity-dotnet`'s `Elephentity.Runtime.Testing`, affected C# builder checker declaration/configuration and `elephentity-examples` |

`provides` remains opaque to the orchestrator: a `provides.check` change requires
no orchestrator change. No checker means an explicit report, never silent success.
PHP advertises `eleph-check-php`; C# runs `ContractCheck` through its configured command.

## C# builders and .NET runtime type names

| You changed | Matching repository must | `elephentity-examples` must |
|---|---|---|
| Exported type/namespace/constructor in `elephentity-dotnet` Runtime | `elephentity-codegen-csharp`: update strings in `rust/runtime.rs` and constructor output | regenerate and compile/check the .NET example |
| Exported type/namespace/constructor in `Elephentity.Cosmos` | `elephentity-codegen-cosmos`: update `rust/runtime.rs` and emitted manifest calls | regenerate and run Cosmos conformance |
| Exported type/namespace/constructor in `Elephentity.GraphQL` | `elephentity-codegen-graphql-csharp`: update `rust/runtime.rs` and emitted manifest calls | regenerate and execute the GraphQL schema |
| Exported initial-data API in `Elephentity.Tsquid` | `elephentity-codegen-tsquid`: update C# runtime names and output | regenerate and verify route data before the first script |
| Runtime-name constant or emitted C# constructor call in a builder | `elephentity-dotnet`: confirm the named type and signature exist | regenerate, compile and run the affected integration |
| Any generated C# bytes | affected builder: re-freeze fixtures only after real compilation/execution | regenerate and commit the signed tree |
| C# builder's `rust/ir.rs` or `Envelope::IR_VERSION` | `elephentity`: align Rust wire codec and PHP oracle; `elephentity-codegen`: align `Envelope::IR_VERSION`; other builders: align their IR/version gates | regenerate with compatible compiler and builders |

C# builders use `line-comment` and emit only `.cs`, never JSON. Both GraphQL builders
provide integration key `graphql`; changes to its declaration reach both builders,
the compiler's discovery/validation and the examples. Schema changes reach both
GraphQL runtimes and must preserve PHP/C# parity. The .NET monorepo's internal package
interfaces type-check together; they do not need companion issues unless a builder
or consuming example is affected.
