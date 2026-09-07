# Rules for agents working across the Elephentity repositories

Elephentity is three published programs plus the builders that ship with them, and they
talk over a wire format rather than a shared classpath. That is deliberate — it is what
lets a builder be written in any language — but it means the compiler cannot fail to
build when a builder falls behind it. Nothing type-checks across the gap. The version
gate turns a mismatch into a refusal at run time, which is late.

**So the coupling is tracked by hand, and this directory is how.**

**These three files are identical in all three repositories.** Deliberately: a contract
described differently on each side of it is a contract with three versions and no
authority. Change one, change all three, in the same batch of commits — and that batch
is itself the thing this directory is about, so it needs its own issues.

| Repository | Is | Speaks |
|---|---|---|
| `hsimah-services/elephentity` | the compiler, runtime and adaptors | produces the IR; ships `eleph-gen-wordpress` and `eleph-gen-wpgraphql` |
| `hsimah-services/elephentity-codegen` | the orchestrator, `eleph-codegen` | owns the protocol; runs builders; signs and writes |
| `hsimah-services/elephentity-codegen-php` | the PHP builder, `eleph-gen-php` | implements the protocol |

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
