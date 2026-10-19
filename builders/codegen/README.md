# elephentity-codegen

The code generator for [Elephentity](https://github.com/hsimah-services/elephentity).

Implemented in Rust. Build a checkout with `cargo build --release --locked`, or install
the executable on PATH with `cargo install --path . --locked`. The `bin/eleph-codegen`
checkout launcher uses `target/release/eleph-codegen` (or a debug build during development).
It never falls back to PHP. Installed Cargo binaries need neither PHP nor Cargo to run.

Elephentity compiles human-readable specs into an IR. This program takes it from there:
it resolves the language builders a project has configured, runs each one, and signs and
writes what they return.

```
eleph generate
  │ compiles specs → IR
  ▼
eleph-codegen generate
  ├→ eleph-gen-php   ─ files
  └→ eleph-gen-ts    ─ files
  │ sign, write, diff
  ▼
generated/
```

**It knows no language.** The IR passes through encoded and is never decoded; a builder
turns it into PHP or TypeScript or anything else, returns paths and bodies, and this
signs and writes them. That keeps one signer as the authority on what "locked" means,
rather than every builder reimplementing it and one of them getting it subtly wrong — and
it means `--check` works for every target without a builder knowing `--check` exists.

[docs/PROTOCOL.md](docs/PROTOCOL.md) is the contract, on both sides.

## Commands

```bash
eleph-codegen generate --project .            # request on stdin; writes the tree
eleph-codegen generate --project . --check    # writes nothing; fails on any difference
eleph-codegen targets  --project .            # the configured targets, as JSON
eleph-codegen doctor   --project .            # are the builders installed and runnable?
```

`generate` is run by `eleph generate`, which compiles the specs and pipes them in. The
others are for humans and for tools that need to know what a project generates.

## Working on it

```bash
cargo fmt --check
cargo clippy --all-targets -- -D warnings
cargo test --locked
./tools/php composer ci
```

Rust sources live in `rust/`. Each builder owns its IR types and version gate; there
is no runtime dependency on the compiler or another builder. PHP in `src/` and the
`bin/eleph-codegen-reference` executable is retained as a migration oracle for the
existing tests. Production entrypoints run Rust only. PHPStan still checks the reference
and acceptance tests at level max.

## Compatibility

The JSON protocol, signature format, digest inputs, target resolution order, error
pooling, check mode, and extension-scoped cleanup are unchanged. Native subprocess
tests cover signed bytes, tampering, nested targets, failed builds, path traversal,
and large requests/responses. The original PHP command tests also run against Rust.
