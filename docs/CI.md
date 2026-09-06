# CI for a project using Elephentity

Elephentity ships four gates. They are cheap, they run in this order, and each catches a
class of failure the others cannot see.

```yaml
- run: vendor/bin/eleph fmt                 # canonical key order, so diffs stay semantic
- run: vendor/bin/eleph validate spec       # well-formed and semantically closed
- run: vendor/bin/eleph generate --check    # the tree matches the spec
- run: vendor/bin/eleph check               # every exposed field resolves
- run: vendor/bin/phpstan analyse
- run: vendor/bin/phpunit
```

## What each one is for

**`fmt`** — the spec is the entity's changelog, so a diff should show what changed and
nothing else. Without a fixed key order, the same field written twice produces noise
that buries the real change.

It reports rather than rewrites. PHP's YAML parsers discard comments, so a
parse-and-dump formatter would delete every explanatory note in the file; losing an
author's reasoning to fix an ordering nit is the wrong trade.

**`validate`** — the spec is well-formed against the JSON Schema *and* semantically
closed: every edge target resolves, every declared type exists, every pattern's
`requires` is satisfied, every action writes only members the entity declares. Errors
accumulate, so one run reports everything.

**`generate --check`** — the generated tree matches the spec. This catches all three
ways it drifts: a hand-edited file (its digest no longer matches), a stale file the
spec no longer produces, and a deleted one.

**`check`** — a different question. `generate --check` asks whether the tree matches
the spec; this asks whether it is *coherent*: every field the GraphQL layer exposes
must resolve to a method that exists. A renamed accessor still parses, still loads, and
still fails here.

## Order matters

`check` inspects the classes on disk, so it must run after `generate`. Everything else
is ordered cheapest-first, so the fastest gate reports the most common mistake.

## Advisory: spec versus description

`.github/workflows/spec-alignment.yml` in this repository is a working example of the
md/yaml drift check described in [PLAN.md](PLAN.md) §2. It is advisory on purpose —
documentation drift is not a solved problem, and a blocking gate that is wrong half the
time gets disabled within a week.
