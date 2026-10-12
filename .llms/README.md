# Rules for agents working across the Elephentity repositories

Elephentity tracks twenty-three repositories (nineteen in use, plus four for the .NET target
that are not yet scaffolded), which talk over a
wire format rather than a shared classpath. That is deliberate — it is what lets a
builder be written in any language — but it means the compiler cannot fail to build
when a builder falls behind it. Nothing type-checks across the gap. The version gate
turns a mismatch into a refusal at run time, which is late.

**So the coupling is tracked by hand, and this directory is how.**

**These four `.llms/` files are identical in every repository that carries them.** Deliberately: a contract
described differently on each side of it is a contract with multiple versions and no
authority. Change one, change every existing copy, in the same batch of commits — and that batch
is itself the thing this directory is about, so it needs its own issues.

| Repository | Is | Speaks |
|---|---|---|
| `hsimah-services/elephentity` | spec compiler, PHP runtime source, CLI and neutral memory builder; Rust `eleph` in `compiler/` from steps 2–4 | produces the IR; ships `eleph-gen-memory` |
| `hsimah-services/elephentity-runtime` | subtree mirror of `packages/runtime/src` and `tests`; metadata is owned locally | runtime contracts; source PRs go to `elephentity` |
| `hsimah-services/elephentity-schema` | subtree mirror of `packages/schema`; entirely owned by the source package | schema, resources and IR oracle; source PRs go to `elephentity` |
| `hsimah-services/elephentity-memory` | subtree mirror of `packages/memory`; entirely owned by the source package | neutral memory driver; source PRs go to `elephentity` |
| `hsimah-services/elephentity-cli` | subtree mirror of `packages/cli`; entirely owned by the source package | `eleph` command and `eleph-gen-memory`; source PRs go to `elephentity` |
| `hsimah-services/elephentity-codegen` | Rust orchestrator, `eleph-codegen` | owns the protocol; runs builders; signs and writes |
| `hsimah-services/elephentity-codegen-php` | PHP entity builder and, from step 6, `eleph-check-php` | builder protocol; PHP runtime type names; `provides.check` |
| `hsimah-services/elephentity-codegen-wordpress` | WordPress storage builder | builder protocol; WordPress runtime type names |
| `hsimah-services/elephentity-codegen-wpgraphql` | WPGraphQL builder | builder protocol; WPGraphQL runtime type names |
| `hsimah-services/elephentity-codegen-sqlite` | SQLite storage builder | builder protocol; SQLite runtime type names |
| `hsimah-services/elephentity-codegen-graphql-php` | generic PHP GraphQL builder | builder protocol; `graphql` integration; PHP GraphQL runtime type names |
| `hsimah-services/elephentity-codegen-tsquid` | tsquid builder, adding C# output in step 20 | builder protocol; frontend and server initial-data output |
| `hsimah-services/elephentity-codegen-typescript` | legacy TypeScript builder repository | no current protocol implementation |
| `hsimah-services/elephentity-wordpress` | PHP WordPress storage adaptor | runtime storage contracts |
| `hsimah-services/elephentity-wpgraphql` | PHP WPGraphQL runtime | runtime integration contracts |
| `hsimah-services/elephentity-sqlite` | PHP SQLite storage adaptor | runtime storage contracts |
| `hsimah-services/elephentity-graphql-php` | generic PHP GraphQL runtime | runtime integration contracts; webonyx schema |
| `hsimah-services/elephentity-patterns` | reusable spec patterns | compiler pattern declarations |
| `hsimah-services/elephentity-examples` | worked examples, including the step 21 .NET example | consumes generated output; end-to-end proof |
| `hsimah-services/elephentity-dotnet` (planned) | one .NET monorepo: Runtime, Runtime.Memory, Runtime.Testing, Cosmos, GraphQL, Tsquid | async runtime contracts; NuGet; GraphQL.NET schema |
| `hsimah-services/elephentity-codegen-csharp` (planned) | Rust entity/enum/contract/catalogue/DI builder, `eleph-gen-csharp` | unchanged builder protocol; .NET runtime type names |
| `hsimah-services/elephentity-codegen-cosmos` (planned) | Rust Cosmos C# storage-manifest builder, `eleph-gen-cosmos` | unchanged builder protocol; .NET Cosmos runtime type names |
| `hsimah-services/elephentity-codegen-graphql-csharp` (planned) | Rust GraphQL C# manifest builder, `eleph-gen-graphql-csharp` | unchanged builder protocol; `graphql` integration; .NET GraphQL runtime type names |

## The rule

**When you commit a change that crosses the contract surface, open an issue on every
repository it reaches, before or with the push — never after.**

The contract surface is enumerated in [cross-repo.md](cross-repo.md). It is a short,
closed list. A change that is not on it needs no issue, and filing one anyway is noise
that teaches the next reader to ignore these.

Everything depends on `dev-main`, so a merge to any repository reaches the others on
their next `composer update`. There is no release to hide behind: a contract change that
lands alone is a contract change that breaks somebody's build that afternoon.

## Filing

Use the template in [issue-template.md](issue-template.md).

```bash
gh issue create --repo hsimah-services/<repo> --title "<title>" --body-file <path>
```

Three things the issue must carry, because an issue without them costs more than it
saves:

1. **What changed here**, as the specific thing — a constant, a JSON key, the bytes of a
   generated file — with the commit SHA. Not "updated the protocol".
2. **What that repository must do**, as a checklist naming files. The reader has the
   other repository open, not this one.
3. **How to know it worked.** A command, and what passing looks like.

Cross-link the issues to each other when a change reaches two repositories, and say
plainly whether they must merge together. An IR version bump must: the gate refuses in
both directions, so any order except "together" is a window where builds fail.

## Do not

- File on a commit that only touched this repository's internals. The surface is closed;
  check it rather than guessing.
- File after merging, then fix it up. The point is that the other repository learns
  before its next update, not after its build breaks.
- Describe the change in this repository's vocabulary. "`Names::entity()` now trims the
  root namespace" means nothing to a reader of `elephentity-codegen`, which has no
  `Names`. Say what crosses: the bytes, the key, the version.
