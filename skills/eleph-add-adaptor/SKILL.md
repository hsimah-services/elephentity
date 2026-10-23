---
name: eleph-add-adaptor
description: Adding a storage adaptor (a new `storage.driver`) to Elephentity, in PHP or C#. Use when asked to support a new database or store, to fix an existing adaptor's behaviour, or to understand how SQLite, Cosmos or the memory driver plug in. Covers the runtime adaptor, the storage manifest, the builder that emits it, and the conformance suite that proves it.
---

# Adding a storage adaptor

A new `driver: <name>` in `spec/project.yml` takes **two programs that never meet at build
time**:

| Half | Runs | Does | Language |
|---|---|---|---|
| **Builder** (`eleph-gen-<name>`) | build | turns the IR into a *storage manifest* (+ install DDL), as source | Rust |
| **Runtime adaptor** | run time | implements the storage port and loads the manifest | PHP `packages/<name>` or C# `dotnet/src/Elephentity.<Name>` |

The builder is a normal builder: read `eleph-add-builder` first. It declares the driver in
`rust/provides.json` as `{"drivers": ["<name>"]}`; a spec using `driver: <name>` with no
target providing it is a `validate` error. The manifest is the only interface between the
halves, so design it first.

## Nearest examples

| Stack | Adaptor | Builder |
|---|---|---|
| PHP, relational | `packages/sqlite` | `builders/codegen-sqlite` (`language: php`) |
| C#, relational | `dotnet/src/Elephentity.Sqlite` | `builders/codegen-sqlite` (`language: csharp`, the same builder) |
| PHP, no schema | `packages/memory` (also holds its builder) | — |
| C#, document | `dotnet/src/Elephentity.Cosmos` | `builders/codegen-cosmos` |
| C#, no schema | `dotnet/src/Elephentity.Runtime.Memory` | — |

Copy the one in your stack. Do not copy a relational generator wholesale for a document
store or the reverse.

## Order of work

1. **Read the port.** PHP: `packages/runtime/src/Storage/StorageAdaptor.php`, `Storage/Write/`,
   `RelationKind.php`, `Criteria.php`. C#: `dotnet/src/Elephentity.Runtime/Storage/IStorageAdaptor.cs`.
   Read the manifest constructors of the nearest adaptor, not its whole tree.
2. **Define the manifest** — typed objects holding layouts, field maps, unique keys, edge
   placement, indexes — as the adaptor's constructor contract. See `reference/files.md`.
3. **Write the adaptor** against the manifest, with the conformance suite as its tests from
   the first commit (`reference/conformance.md`).
4. **Write the builder** that emits that manifest, plus install/DDL code if the store has a
   schema. Load its *real output* in the adaptor's tests.
5. **Add the driver to a golden spec** and run the whole pipeline (`eleph-add-builder`,
   `reference/testing.md`).
6. Document: README with install, `eleph.json` target, `project.yml` keys, limitations.

## Rules that are easy to break

- **The runtime owns deletion semantics.** `onDelete` is enforced by the framework; database
  foreign keys are integrity underneath, never the policy.
- **Entities insert before edges link.** A required-edge key may need to be temporarily
  null during a write.
- **Install is not migrate.** Initial installation fails on existing tables/containers. Evolving
  populated data is an application migration the build cannot see.
- **Do not reapply `project.storage.tablePrefix`** — the compiler already put it in the IR table
  names. A runtime prefix is an extra, applied to tables, references and indexes together.
- **No I/O in constructors.** C#: async-first, `CancellationToken` everywhere; the libraries are
  AOT-compatible with warnings as errors, so no reflection in hot paths.
- **Edit the PHP runtime only in `packages/runtime`**; `elephentity-runtime` is a read-only mirror.

## Reference

- `reference/files.md` — what a PHP adaptor and a C# adaptor each contain
- `reference/conformance.md` — the conformance suites and what else to test
- `reference/lessons.md` — mistakes already made once (SQLite, Cosmos)
- `eleph-add-builder` — the builder half
- `dotnet/docs/cosmos.md`, `dotnet/docs/divergence.md` — C# specifics
