# Anatomy of a builder

Paths are relative to `builders/codegen-<name>/`.

| File | Holds |
|---|---|
| `Cargo.toml`, `Cargo.lock` | Binary name `eleph-gen-<name>`; commit the lock and build `--locked`. No dependency on any runtime package |
| `rust/main.rs` | Version gate, `describe`/`generate` dispatch, envelope, diagnostics |
| `rust/ir.rs` | Your typed copy of the wire IR. `tests/ir_copy.rs` pins it to its source (`SOURCE.md` records which) |
| `rust/provides.json` | What you provide: `{"drivers": [...]}` and/or `{"integrations": {...}}`. `{}` is a valid answer for a plain language builder |
| `rust/runtime.rs` | Runtime type names as strings, when generated code names a runtime (C# builders) |
| `rust/emit_*.rs` / `storage.rs` / `manifest.rs` | The emitters, one concern per file |
| `bin/eleph-gen-<name>` | Shell launcher picking `target/release` then `target/debug` (Composer-distributed builders) |
| `composer.json` | **Only** for PHP-runtime builders: build-time metadata with the `bin` entry. C# builders have none |
| `tests/fixtures/` | A request and the exact response, per scenario |
| `tests/protocol.rs` | Runs the **real binary** against the fixtures; also rejection and `describe` cases |
| `README.md` | Install, the `eleph.json` target and its keys, what it writes, limits |
| `CLAUDE.md` | Short pointer for contributors; mirror a neighbour |
| `LICENSE` | MIT for new code |
| `.github/workflows/ci-codegen-<name>.yml` | Path-filtered: fmt, clippy `-D warnings`, `cargo test --locked` |

## The target config

Each target in `eleph.json` is yours to define; nothing upstream knows what the keys
mean. Validate them yourself and put **all** problems in `errors`. Common keys: `builder`
(owned by the orchestrator), `output` (its own directory), and whatever you need
(`namespace`, `typeNamespace`, `check`). Document each in your README.

`describe` may receive `config` but must also answer without it; reporting a bad config is
`generate`'s job.

## Checking

`eleph check` runs whatever you declare in `provides.check.command`. For PHP builders
that is `eleph-check-php`; for C# it is the project's own contract-test project, named in
the target's `check` array and echoed back by `describe`. A builder with no checker is
reported as a warning — never silently passed — so declare one if your tree can be
incoherent in ways `generate --check` cannot see.

## Distribution

| Runtime | Ship as | Tag |
|---|---|---|
| Not PHP | release binary, fetched by `eleph install` | `eleph-gen-<name>-v<version>` (annotated) |
| PHP | Composer package mirrored from the monorepo | `codegen-<name>-v<version>` |

Binary archives and `SHA256SUMS` follow the naming contract in `docs/RELEASING.md`; reuse
`.github/workflows/release-binary.yml` rather than writing a workflow. Add a
`release-check-eleph-gen-<name>.yml` like the others. `eleph install` finds the repository
for a program it knows; for any other, the target needs `"source": "owner/repo"`.

Releasing is the owner's call: do not tag or publish unless asked.
