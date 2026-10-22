---
name: eleph-add-builder
description: Writing a new Elephentity builder (output generator) — a program that turns the compiled spec (IR) into source for some language or format. Use when asked to generate code for a new language, add a new kind of generated file, or fix a builder that disagrees with the compiler. Start here for any new `eleph-gen-*` program; storage adaptors and API integrations have their own skills that build on this one.
---

# Adding a builder

A **builder** is a standalone program: one JSON request on stdin, one JSON response on
stdout. It receives the compiled schema (the **IR**) and returns file paths and bodies. It
never writes files — `eleph-codegen` signs and writes them. That is why a builder can be
written in any language, and why nothing type-checks across the gap: **the contract is
the wire format, so the tests are the only guard.**

This is the base skill. A builder that also provides a storage driver or an API
integration continues in `eleph-add-adaptor` or `eleph-add-integration`.

## Decide first

| You want | You are writing | Read next |
|---|---|---|
| source for another language or framework (entities, DTOs, types) | a plain builder | this skill |
| a database backend | builder (schema + manifest) **and** a runtime adaptor | `eleph-add-adaptor` |
| an API surface (REST, GraphQL, RPC) | builder (manifest) **and** a runtime registrar | `eleph-add-integration` |
| a change to what existing output looks like | an edit to an existing builder, not a new one | its README, then `reference/testing.md` |

Look for the nearest existing builder and copy its shape rather than starting blank:

| Nearest | Directory | Language | Ships as |
|---|---|---|---|
| entities, contracts, DI | `builders/codegen-csharp` | Rust | release binary |
| entities in PHP | `builders/codegen-php` | Rust + Composer shell | Composer mirror |
| storage manifest | `builders/codegen-sqlite`, `builders/codegen-cosmos` | Rust | Composer / binary |
| API manifest | `builders/codegen-graphql-php`, `builders/codegen-graphql-csharp` | Rust | Composer / binary |
| frontend route manifest | `builders/codegen-tsquid` | Rust | binary + Composer |

Prefer Rust: every current builder is, they release as binaries `eleph install` can fetch,
and the compiler, orchestrator and builders then need no language runtime of their own.

## The three rules

1. Read one JSON object from stdin, write one to stdout, exit 0.
2. Never touch the filesystem. Return `{path, body}`; the orchestrator signs and writes.
3. Diagnostics go to stderr. A stray print on stdout is a protocol error.

Plus: answer **both** requests. `describe` (before any spec is read; no schema, no
config required) says what you provide; `generate` returns the files. Read the envelope
(`elephentity` protocol version and `irVersion`) **first** and refuse what you do not
understand — the gate refuses in both directions.

The authoritative protocol is `builders/codegen/docs/PROTOCOL.md`. Read it once; do not
copy it.

## Order of work

1. Read `PROTOCOL.md` and the nearest builder's `rust/main.rs` and `rust/provides.json`.
2. Check the current `IR_VERSION` in `compiler/src/ir/mod.rs` — READMEs lag it.
3. Scaffold from `reference/anatomy.md` (the file list and what each holds).
4. Copy `rust/ir.rs` from the nearest builder and trim it to what you read. Each builder
   owns a typed copy of the IR; a field you do not decode is a field you silently drop.
5. Implement `describe`, then `generate`. Return every problem in `errors` with an empty
   `files` list; never partial output.
6. Freeze golden fixtures only after you have **run** the output (`reference/testing.md`).
7. Wire CI and the release (`reference/anatomy.md`, "Distribution").
8. Check `.llms/cross-repo.md` — a new builder is a new row; a changed `provides` shape or
   IR copy is a cross-boundary change that needs issues.

## Do not

- Depend on the framework from the builder. Runtime class names in generated code are
  strings (`rust/runtime.rs` in the C# builders), not imports.
- Put your header in the body. `headerStyle` (`php` or `line-comment`) lets the
  orchestrator render the signed header; you return the body only.
- Return `extensions` you do not own. They are what the orchestrator is allowed to delete.
- Install, format-on-disk or migrate anything. Run your own formatter in-process.
- Invent spec keys. Integration/driver keys are declared via `provides`, nowhere else.

## Reference

- `reference/anatomy.md` — files in a new builder, the config, distribution
- `reference/testing.md` — verification order, golden fixtures, lessons that cost time
- `builders/codegen/docs/PROTOCOL.md` — the wire protocol
- `docs/BUILDERS.md` — why the contract is shaped this way
- `docs/RELEASING.md` — tags and the artifact naming contract `eleph install` relies on
- `.llms/cross-repo.md` — what crosses a boundary and who must be told
