# Rules for agents changing what crosses a program boundary

Elephentity is several programs that talk over a wire format rather than a shared classpath.
That is deliberate — it is what lets a builder be written in any language — but it means
nothing type-checks across the gap. The version gate turns a mismatch into a refusal at run
time, which is late. So the coupling is tracked by hand, and this directory is how.

There is **one copy** of these files, here. Per-program copies were removed when the
repositories were folded into this one; do not recreate them.

## The programs

| Directory | Is | Speaks |
|---|---|---|
| `compiler/` | Rust `eleph` | produces the IR; asks builders to `describe`; dispatches `check` |
| `builders/codegen` | `eleph-codegen`, the orchestrator | owns the protocol and `release-binary.yml`; runs builders, signs and writes |
| `builders/codegen-php`, `-sqlite`, `-graphql-php` | PHP-side builders | builder protocol; PHP runtime type names |
| `builders/codegen-csharp`, `-cosmos`, `-graphql-csharp` | C# builders | builder protocol; .NET runtime type names (`rust/runtime.rs`) |
| `builders/codegen-tsquid` | tsquid route-manifest builder | builder protocol; reads `schema.project.integrations` from the request JSON |
| `packages/runtime`, `memory`, `sqlite`, `graphql` | PHP runtime family (mirrored read-only to their own repositories) | runtime contracts; manifests |
| `dotnet/` | .NET runtime and adaptors | async runtime contracts; NuGet |
| `patterns/` | pattern catalogue | compiler pattern declarations |
| `hsimah-services/elephentity-examples` | **separate repository**: worked examples, the end-to-end check | consumes generated output |

Retired and out of scope: `elephentity-schema` and `elephentity-cli` (frozen at v0.14.2),
the WordPress and WPGraphQL packages and builders, and `elephentity-codegen-typescript`.

## The rule

**When you change something on the contract surface, make the matching change in every
program it reaches in the same pull request; for `elephentity-examples`, which is a separate
repository, open an issue before or with the push — never after.**

The surface is enumerated in [cross-repo.md](cross-repo.md). It is short and closed. A change
that is not on it needs nothing, and filing anyway teaches the next reader to ignore these.

Sharing a repository lets one pull request touch both sides; it does not make the gap
type-check. A renamed runtime class still passes every unit test and fails in a generated
project. Prove a cross-boundary change the way `cross-repo.md` says for its row — usually
regenerating an example and running the gates.

Everything depends on `dev-main`, so a contract change that lands alone breaks somebody's
build that afternoon. An IR version bump must merge in one go: the gate refuses in both
directions.

## Filing (examples repository, and anything outside this one)

Use [issue-template.md](issue-template.md).

```bash
gh issue create --repo hsimah-services/elephentity-examples --title "<title>" --body-file <path>
```

An issue must carry: **what changed**, as the specific thing (a constant, a JSON key, the
bytes of a generated file) with the commit SHA; **what the reader must do**, as a checklist
naming files, because they have their own repository open, not this one; and **how to know
it worked** — a command, and what passing looks like. Say plainly whether it must merge with
something else.

## Do not

- Describe the change in this program's vocabulary. "`Names::entity()` now trims the root
  namespace" means nothing to a reader of `builders/codegen`, which has no `Names`. Say what
  crosses: the bytes, the key, the version.
- File after merging and fix it up.
- Edit a mirror. Pull requests go here.

## Starting a new builder, adaptor or integration?

That adds a row to `cross-repo.md`. The how-to is in the skills: `skills/eleph-add-builder`,
`skills/eleph-add-adaptor` and `skills/eleph-add-integration`, indexed in
[`skills/README.md`](../skills/README.md).
