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

The five gates above check that the spec and the code agree. Nothing checks that the
spec and the *description of it* agree, and that is the drift described in
[PLAN.md](PLAN.md) §2: the `.md` says what an entity is for, the `.yml` is what actually
compiles, and they part company quietly.

`.github/workflows/spec-alignment.yml` is a reusable workflow you can call:

```yaml
name: Spec alignment
on:
  pull_request:
    paths: ['spec/**']

jobs:
  alignment:
    uses: hsimah/elephentity/.github/workflows/spec-alignment.yml@main
    with:
      specs: spec          # where your specs and their descriptions live
    secrets: inherit       # needs ANTHROPIC_API_KEY; skips silently without it
```

Or copy the file and change `workflow_call` back to `pull_request`. Copying is the
better option if your specs are spread across more than one directory, or if you want to
change what the agent is asked — it is a short file and it is yours to own.

**It does not run in the Elephentity repository itself.** This is a project gate: specs
live in projects that use the framework, and the framework has none — only the worked
example and the compiler's test fixtures, several of them deliberately malformed. Wired
to `pull_request` it fired on any markdown edit and had nothing valid to compare.

It is advisory on purpose, and the workflow sets `continue-on-error` to keep it that way.
Documentation drift is not a solved problem, and a blocking gate that is wrong half the
time gets switched off within a week.

**The prompt asks for two enumerations, not a verdict** — members in the yaml with no
counterpart in the md, and behaviour described in the md that the yaml does not declare.
"Are these aligned?" produces an opinion nobody can check; a list is something you can
scan and dismiss in seconds when it is wrong. If you adapt the prompt, keep that shape.
