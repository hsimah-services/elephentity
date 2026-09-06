# The commands, and what each one catches

Four gates. They are cheap, they run in this order, and each catches something the
others cannot see. Running them out of order wastes time; skipping one leaves a class
of failure to be found later and further away.

```bash
vendor/bin/phefr fmt
vendor/bin/phefr validate spec
vendor/bin/phefr generate          # generate --check in CI
vendor/bin/phefr check
```

All except `validate` take `--project` (default `.`), the directory holding
`phefr.json`. `validate` takes the spec directory as an argument instead.

---

## `fmt`

Canonical key order across every spec.

**Catches:** keys written in a different order from the convention, which would show up
as diff noise in a review of an unrelated change.

**Does not rewrite.** Fix the reported lines by hand; the message names the file, the
path within it, and the order expected.

```
spec/entities/Post.yml /fields/title: Keys are out of canonical order.
Expected: type, required, indexed, maxLength. [format.keyOrder]
```

---

## `validate`

Well-formed against the JSON Schema, then semantically closed.

**Catches:** unknown keys, wrong types, and then — the more interesting half — a spec
that parses but does not mean anything. Every edge target must resolve, every declared
type must exist, every pattern's `requires` must be satisfied, every action must write
only members the entity declares, an indexed string must fit the index, a WordPress
handle must be a legal post type slug.

**Errors accumulate.** One run reports everything, each with a file, a JSON pointer, a
message and a stable code:

```
spec/entities/Post.yml /fields/id: Every entity has an implicit "id"; it cannot be
declared or overridden. [field.reserved]
```

Fix them together. A second run will not find new ones hiding behind the first.

---

## `generate`

Writes the tree. Reports created, updated, unchanged and removed.

**`--check` writes nothing** and fails if anything would change. This is the CI form,
and it catches all three ways a generated tree drifts:

| symptom | reported as |
|---|---|
| a file was hand-edited | `Hand-edited` — its digest no longer matches |
| the spec changed and nobody regenerated | `Would be updated` |
| a file the spec no longer produces | `No longer produced by the schema` |
| a file was deleted | `Would be created` |

The fix is always the same: run `generate`, commit the result.

---

## `check`

Conformance. **A different question from `generate --check`:** that one asks whether
the tree matches the spec, this asks whether the tree is *coherent* — every field the
GraphQL layer exposes must resolve to a method that exists.

**Catches** what nothing else can: a generated file that is valid PHP, loads fine, and
backs an API field that no longer resolves.

```
Post.title resolves via App\Entity\Post\Post::getTitle(), which does not exist.
```

Must run after `generate`, because it inspects the classes on disk. It loads them with
its own autoloader rather than the project's, so a broken Composer configuration cannot
make the gate pass silently.

---

## Using the gates to grade agent-written specs

The four gates are a deterministic grader. An agent that writes a spec can be scored
without a human reading it:

1. `fmt` — did it follow the conventions?
2. `validate` — does the spec mean anything?
3. `generate` — does it produce code?
4. `check` + `phpstan` — is the result coherent and type-safe?

A spec that clears all four is not necessarily the *right* model, but it is a real one.
Anything less is objectively wrong, and the error message says why. Use this rather
than eyeballing generated output.
