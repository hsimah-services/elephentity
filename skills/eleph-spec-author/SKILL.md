---
name: eleph-spec-author
description: Turning a written description of a domain object into an Elephentity spec. Use when given a markdown document, a ticket, a conversation or a sketch describing an entity and asked to produce spec YAML — or when starting a new Elephentity project from scratch. Covers what to ask before writing anything, how prose maps onto spec constructs, and what Elephentity cannot express.
---

# Writing an Elephentity spec from a description

The output of this skill is a spec, an accompanying markdown file, and a short list of
things the author had to decide. It is not a first draft to be corrected later: the
spec is the entity's changelog, and its first commit is the one everything else is
diffed against.

Read the `eleph` skill first if you have not — particularly `reference/spec-format.md`.

## Interview before you write

**Prose almost never contains what a spec needs.** A description says what a thing *is*;
a spec says what is true of every row of it. The gap is systematic:

| The description will say | The spec needs |
|---|---|
| "an item has a barcode" | required at create? unique? indexed? how wide? |
| "items are stored in locations" | which side owns the key? is the reverse unique? |
| "the status can be draft or published" | a declared enum, or inline on this field? |
| "the price" | a plain int, or a value type with behaviour? |
| "we archive old ones" | a field, an action, or a deletion policy? |

So: **ask, then write.** `reference/interview.md` is the checklist, ordered by how much
each answer changes. Six questions block writing a valid spec at all; the rest shape
the model but have defensible defaults.

Ask them in one batch, not one at a time. If the person is unavailable, write the spec
with defaults and put every assumption in the markdown file under a heading they will
see — do not bury them in YAML comments.

## Then write both files

A spec is two files. The yaml is authoritative from the moment it exists; the markdown
is the record of intent — what this object is, why it exists, which decisions were
close calls.

```
spec/entities/Item.yml
spec/entities/Item.md      # or wherever the project keeps them
```

Do not regenerate the yaml from the md later. That step is the only non-deterministic
one in the pipeline, and re-running it churns fields nobody touched and overwrites
hand corrections. Subsequent changes edit the yaml directly.

## Order of work

1. **Interview** — `reference/interview.md`.
2. **Name the entities.** One per thing that has an identity of its own. If two things
   are always created and deleted together and one never exists alone, it is probably
   fields on the other.
3. **Find the shared parts.** Fields appearing on three entities are a pattern. Do not
   invent patterns for two.
4. **Write the types first** — enums and value types — so entities can reference them.
5. **Write each entity**: storage, fields, edges, then queries, actions, triggers.
6. **Run the gates.** `fmt`, `validate`. They will find what you got wrong, precisely.
7. **Report what you assumed**, and what Elephentity could not express.

## Mapping prose onto the spec

| The description says | Reach for |
|---|---|
| "every X has a name/date/amount" | a field |
| "X belongs to Y" / "Y has many X" | an edge — decide the owning side deliberately |
| "one of: a, b, c" | an enum, declared in `types/` if reused, inline if not |
| "an amount of money" / "an email" | a declared type with `processors: true` |
| "you can publish/approve/archive an X" | an action, with `writes:` naming its reach |
| "when an X is created, also …" | a trigger — and pick the phase deliberately |
| "an X's start must be before its end" | `verify: true` on one of the two fields |
| "set once when created" | `immutable: true` |
| "we look things up by this" | `indexed: true` |
| "no two X share this" | `unique: true` |

## Do not invent

- **Do not add an `id`.** Every entity has one implicitly; declaring it is an error.
- **Do not name columns, tables or join tables** beyond `storage.table`. Placement is
  inferred, and inferring it is what stops two entities disagreeing.
- **Do not add fields the description does not mention** because they seem likely.
  `createdAt` is a reasonable suggestion, not a silent addition.
- **Do not guess a `handle`.** Whether the entity needs a WordPress post type is a
  question with real consequences — ask it.

## What Elephentity cannot express

When the description needs one of these, say so rather than approximating quietly:

- **A list of scalars.** No `list` modifier. Either `json` — which cannot be indexed —
  or model the values as a child entity, which can. If the description implies looking
  values up ("scan the barcode to find the item"), the child entity is the honest
  answer and the model change should be raised, not absorbed.
- **A composite value object.** A declared type aliases exactly one primitive, so
  `{unit, value}` becomes two fields and any nested API shape is lost.
- **Deletion policy beyond `onDelete`.** Soft delete, retention, archival — model them
  as fields and actions.
- **Field visibility and access rules.** Exposing an entity exposes every field on
  it. If the description says "only the owner sees the notes", that is a layer
  above the spec, and it should be raised rather than absorbed.

## Reference

- `reference/interview.md` — the questions, ordered by consequence
- `reference/worked-example.md` — a description and the spec it produces, with the
  reasoning at each decision
