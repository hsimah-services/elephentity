# Glossary

Terms as Elephentity uses them. Where a word means something specific here, the entry
says why it was chosen — the reasoning is usually the definition.

---

## Words that mean three things

Two words carry real ambiguity, so they are always qualified.

**Schema.** Three different things:

- a **JSON Schema** — the files in `packages/schema/resources/` that define what a spec
  file may contain;
- the **compiled schema** — `Eleph\Schema\Ir\Schema`, the in-memory result of compiling
  every spec file, also called the IR;
- the **database schema** — tables and columns, produced by the WordPress adaptor.

Never write "the schema" unqualified.

**Type.** Either a **primitive** (`string`, `int`, …), a **declared type** (`types/Money.yml`),
or a **PHP type** in generated code. The spec means the first two; prose about generated
code usually means the third.

---

## The spec

**Spec.** The YAML that describes an entity, pattern or type. Authoritative: it is the
source everything else is derived from, and the entity's changelog.

**Project spec.** `spec/project.yml`, required. Holds the settings no entity can
sensibly vary — the storage driver and the table prefix. Deliberately shallow: it may
not declare patterns or fields, because reading an entity spec must tell you what that
entity has.

**Table prefix.** A project-level string prepended to every entity's table, resolved at
compile time. Stacks with whatever prefix the driver adds of its own.

**Entity.** A thing with an identity of its own, stored as one row. Becomes a read
model, a mutator, and everything around them.

**Pattern.** A reusable fragment of an entity spec, pulled in with `use:`. Any section
an entity may declare, a pattern may declare. **Sealed** — an entity redeclaring a
member a pattern defines is a hard error, because overridable patterns would mean
reading one file no longer tells you what a field is.

**Declared type.** A value type in `types/`, aliasing exactly one primitive. Either an
**enum** (it has `values:`) or a **value type** (it has `processors: true`).

**Configuration.** Parameters a pattern declares with `config:` and each entity supplies
with `configure:`. The compiler validates values against the pattern's own declaration,
which is what lets a WordPress pattern carry WordPress settings without the core
learning what any of them mean.

**Driver.** Which storage backend the project uses. Declared once in the project spec,
because a unit of work has one adaptor. Only `wordpress` exists.

**Handle.** What the storage system calls this entity — a post type slug under
WordPress, a collection name elsewhere. Deliberately driver-agnostic in name, and
validated per driver.

---

## Fields

**Required.** Must be supplied when creating a row.

**Nullable.** The column admits NULL.

> These are different facts and the spec keeps them apart. All four combinations are
> meaningful, and conflating them is the commonest mistake in a first spec.

**Immutable.** Write-once: settable on create, and the generated mutator has no setter
for it afterwards. Enforced by the method not existing, which nothing can forget.

**Primitive.** One of the closed set: `string` `text` `int` `float` `bool` `datetime`
`id` `enum` `json`. Closed on purpose — every mapping a primitive drives (SQL column,
PHP type, GraphQL type) is a lookup table with no escape hatch.

**Inline enum.** An enum written as `values: [a, b]` on a field rather than declared in
`types/`. Generates the same fully-qualified class as a declared one would, which is
what makes promoting it to `types/` a no-op in the generated code.

---

## Edges

**Edge.** A relationship. Declared once, on whichever side reads naturally.

**Cardinality.** How many of the target this side reaches: `one` or `many`.

**Inverse.** The reverse accessor generated on the far side. `inverse.unique` describes
whether the target points back at exactly one of the declaring entity — so cardinality
and inverse.unique together give the **relation**.

**Relation.** One of one-to-one, many-to-one, one-to-many, many-to-many. Derived, never
declared, and it determines where the key lives.

**Placement.** Where an edge physically lives: a foreign key on one side or the other,
or a join table. Inferred — if the framework can work it out, a human choosing it is a
chance for two entities to disagree.

---

## Behaviour

**Query.** A collection-level finder declared in the spec. Generates a method on the
entity's finder and an interface to implement. Traversing an edge is not a query.

**Action.** A named write operation — publish, approve, transfer. Declares what it
`writes:`, and receives a context exposing only those members, so it physically cannot
touch anything else.

**Blast radius.** What an action is permitted to change. Reviewable in the spec diff
rather than discoverable by reading the implementation.

**Trigger.** Something that runs as part of a commit. Declared in the entity spec and
nowhere else, which is the difference between this and WordPress hooks.

**preCommit.** Inside the transaction, after the writes are flushed so ids exist. A
throw rolls the whole commit back. May not mutate.

**postCommit.** After `COMMIT`. A throw is logged and the remaining triggers still run.
May mutate — as a *new* unit of work, so it is not atomic with the commit that caused
it.

**Verifier.** An entity-specific rule on one field, exactly typed, receiving the whole
pending mutation. Where cross-field rules live, because a per-value processor sees one
value and cannot know about another.

**Processor.** The pair attached to a declared type. A **read processor** turns the
stored primitive into the domain value; a **write processor** verifies a domain value
and turns it back. Shared across entities, so their context access is stringly-typed.

**Violation.** One reason a value was rejected: a code and a message, and no field name
— a shared processor does not know which field it is on, so the unit of work attaches
the path.

---

## What gets generated

Everything below is machine-owned, signed, and never edited.

**Read model.** The entity class itself — `Item`. An immutable snapshot of one row,
with a getter per field and lazy accessors for edges.

**Mutator.** The write side — `ItemMutator`. A command buffer: setters record intent
and nothing reaches storage until commit.

**Finder.** `ItemFinder`. Collection-level queries, injectable rather than static so it
can be faked in a test and so the entity stays exactly one thing.

**Mutation context.** `ItemMutationContext`. A pending mutation with exact types —
`pendingPrice()`, `originalStatus()` — handed to verifiers and triggers.

**Action context.** `ItemPublishContext`. An action's narrow write surface, generated
from its `writes:` block.

**Hydrator.** `ItemHydrator`. Turns a stored row into a read model, running read
processors on the way.

**Bridge.** `ItemVerifiers` and `ItemTriggers`. Adapters between a generic runtime and
your exactly-typed interfaces — PHP forbids narrowing a parameter, so only generated
code can stand between the two.

**Contract.** The interfaces *you* implement, in `Item/Contract/`. Generated, but
deliberately without implementations: the spec declares the obligation, the generator
states it exactly, and the application does not boot until something discharges it.

**Manifest.** The compiled GraphQL surface — object types, enums, connections and
mutations — written as PHP that rebuilds it, so it type-checks and its diff reads as a
description of the API.

---

## Runtime

**Storage adaptor.** The port every backend implements. Speaks only in entity names and
primitives; domain types live above it and SQL below.

**Capability.** Something an adaptor may or may not support — transactions, full-text,
faceting. Declared per adaptor rather than the port flattening to a lowest common
denominator.

**Unit of work.** One commit. Verifies everything, orders writes by dependency, writes
rows then links, runs preCommit triggers, commits, runs postCommit triggers.

**Mutation buffer.** Where a mutator's pending changes accumulate. The same object,
seen from the write side, that a verifier sees as a mutation context.

**Identifier.** A handle to a row. **`EntityId`** for one that exists, **`PendingId`**
for one this commit is about to create — necessary because ids are server-generated, so
a Comment must be able to point at a Post before the Post has one.

**Link / Unlink.** Attaching one row to another along an edge. The port says what the
relationship *is*; only the adaptor knows whether that is a foreign key or a join row.

**Lazy entity query.** What every to-many accessor and finder returns. Nothing runs
until you ask it something, and `count()` never hydrates.

**Edge loader.** Resolves edges, and remembers what it has resolved.

**Preload.** Warming the loader for many parents in one query. **Batching is not
automatic**: once fifty posts have each been asked for their comments individually,
fifty queries have run. It needs a caller holding every id up front — a GraphQL
connection resolver — which is where it is called.

---

## The build

**IR.** The compiled schema: the single artifact every generator reads. Without it, two
generators would each grow their own half-answer to what a spec field means.

**Gate.** One of the four checks, in order:

| | asks |
|---|---|
| `fmt` | are keys in canonical order, so diffs stay semantic? |
| `validate` | is the spec well-formed *and* semantically closed? |
| `generate --check` | does the committed tree match the spec? |
| `check` | is the tree coherent — does every exposed field resolve? |

**Semantically closed.** Every edge target resolves, every declared type exists, every
pattern's `requires` is satisfied, every action writes only members the entity declares.
A spec can parse and still mean nothing.

**Digest.** The hash in a generated file's header, covering its path and everything
after the header. A file cannot hash its own hash, so the header is excluded by
position.

**Drift.** Any way the generated tree stops matching the spec: a hand-edited file, a
stale one the spec no longer produces, or a deleted one. All three are caught by
regenerating and diffing.

**Conformance.** A different question from drift: whether every field the API exposes
resolves to a method that exists. A renamed accessor still parses and still loads, and
fails only here.

**Canonical order.** The fixed order keys are written in. Applies to **keys**, never to
**members** — trigger declaration order is execution order, so sorting members would
change behaviour.

---

## WordPress

**Projection.** The `wp_posts` row standing in for an entity. The custom table is
authoritative; the post row exists so the ecosystem has something to hold on to.

**Orphan guard.** The `before_delete_post` hook. Nothing in the framework sees someone
empty the trash in wp-admin, and without it the post row goes while the custom row
survives pointing at nothing.

**Refusal.** A migration the planner will not make unattended. A column in the database
but not the spec is either a rename or a drop, and the diff cannot tell which — so it
stops and asks for an explicit migration rather than guessing and destroying data.
