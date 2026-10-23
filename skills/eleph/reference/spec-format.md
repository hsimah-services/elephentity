# The spec format

**The JSON Schemas are the authority.** When this document and a schema disagree, the
schema is right.

Published at `https://dev.hbla.ke/elephentity/v0/<name>.schema.json`, and in the
elephentity repository under:

```
compiler/resources/
  common.schema.json    shared definitions — fields, edges, queries, actions, sideEffects
  project.schema.json   project.yml
  entity.schema.json    entities/*.yml
  pattern.schema.json   patterns/*.yml
  type.schema.json      types/*.yml
```

Read them when you need certainty about what a key accepts. `eleph validate` checks
against them, so anything they reject is not a spec.

They are also published at `https://dev.hbla.ke/elephentity/v0/`, so an editor can
validate a spec file as it is typed. The CLI never fetches them — it registers the
local files against those URLs — so validation works offline.

## Project

One file, `spec/project.yml`, and **every project needs one**.

```yaml
project: Clog
description: Inventory tracking.
storage:
  driver: sqlite        # memory | sqlite | cosmos — whichever a configured target provides
  tablePrefix: clog_
```

`driver` lives here rather than on each entity because a unit of work has one adaptor —
letting entities differ would invite a lie the format cannot honour. Pattern
`requires: { driver: … }` is checked against this, once.

`tablePrefix` is prepended to every entity's table. A driver may take further storage
keys of its own, declared by its builder (`eleph-codegen describe` lists them).

It is deliberately shallow. It may **not** declare patterns or fields applied to every
entity — that would mean reading an entity spec no longer tells you what that entity
has, which is the same reasoning that makes patterns sealed.

## Integrations

An integration exposes an entity to an external system. Distinct from a pattern, and
the difference is what they contribute: a pattern adds fields, edges and actions; an
integration adds no structure at all, only settings for the exposure.

Its permitted keys are declared **by the builder providing it** (in its `describe`
answer), not as user YAML — the keys are a property of the integration rather than a
choice the project makes. `eleph validate` checks against the builders `eleph.json`
configures. The one integration today is `graphql`, provided by `eleph-gen-graphql-php`
and `eleph-gen-graphql-csharp`; to add another, see the `eleph-add-integration` skill.

Opt-in twice. The project says what it speaks:

```yaml
# spec/project.yml
integrations:
  graphql: {}           # or with project settings, if the builder declares any
```

and each entity says whether it is exposed, and how:

```yaml
# spec/entities/Post.yml
integrations:
  graphql:
    singular: Post        # defaults to the entity name
    plural: Posts         # defaults to <Singular>Collection
```

An entity that says nothing is not exposed. Adding an entity should never silently
widen a public API, so the extra two lines are the point rather than a cost.

The generator never pluralises on your behalf — the same rule as edge inverses, and
"Inventory Entry" / "Inventory" is why — so `plural` is yours to name.

## Entity

```yaml
entity: Post                      # PascalCase, matches the filename
description: A published article. # one line; real prose lives in the .md
use:                              # patterns to pull in
  - Timestamps
storage:
  table: post                     # snake_case; the project prefix is prepended
  handle: post                    # optional; what the storage system calls it
fields: { }
edges: { }
queries: { }
actions: { }
sideEffects: { }
```

`handle` is driver-agnostic — what the storage system calls the entity, such as a
collection name — and validated per driver. Leave it out unless asked; do not guess one.

Every entity has an implicit `id`. Declaring one is an error.

`Enum` and `Type` are reserved entity names — the generated tree uses folders of those
names for every enum and every type processor in the schema.

## Fields

```yaml
fields:
  title:
    type: string
    description: What it is called.
    required: true      # must be supplied on create
    nullable: false     # the column admits NULL
    default: null
    unique: false
    indexed: true
    immutable: false    # settable on create, no setter afterwards
    managed: created    # datetime only; the framework fills it — see below
    maxLength: 200      # string only; default 255
    values: [a, b]      # enum only — a list, or the name of a declared enum
    verify: true        # generate an entity-specific verifier interface
```

**`required` and `nullable` are different facts.** `required` is about creating a row;
`nullable` is about what the column can hold. All four combinations are meaningful, and
conflating them is the most common mistake in a first spec.

`required` is enforced at commit, as a violation with a field path, before any SQL
runs. A field with a `default:` is exempt — the column answers for it.

`unique` is checked the same way, so a duplicate is a violation rather than a driver
exception. The index is still the guarantee: the check runs before the transaction, so
a race is settled by the constraint. Nulls are never checked, which is what makes a
nullable unique column mean "at most one, if any".

**`managed` means the framework fills it.** `created` stamps the field when the row is
inserted; `modified` stamps it on every write. Either way there is no setter, no input
branch, and no GraphQL input field, so a machine-managed timestamp never becomes
something an API client has to invent. Datetime only, and a compile error alongside
`required` or `immutable` — both would be a second answer to "who fills this".

Primitives: `string` `text` `int` `float` `bool` `datetime` `id` `enum` `json`.

An indexed or unique `string` may not exceed 768 characters — the utf8mb4 index limit.
Exceeding it is a compile error rather than a silent prefix index, because a prefix
`UNIQUE` enforces uniqueness of the prefix and lets two different values collide.

## Edges

```yaml
edges:
  comments:
    to: Comment
    cardinality: many
    inverse: true                            # or: post
                                             # or: { name: posts, unique: false }
    onDelete: restrict                       # default; or cascade, nullify
```

`cardinality` describes the forward side; `inverse.unique` describes the reverse. The
two together determine the relation, and therefore storage — which is inferred, never
declared:

| `cardinality` | `inverse.unique` | relation | storage |
|---|---|---|---|
| `one` | `true` | one-to-one | foreign key here, unique |
| `one` | `false` | many-to-one | foreign key here |
| `many` | `true` | one-to-many | foreign key on the far side |
| `many` | `false` | many-to-many | join table |

### Deleting across an edge: `onDelete`

`onDelete` is optional and **defaults to `restrict`**, so removing data that other rows
still point at is always a deliberate choice.

| `onDelete` | when the row on the other end is deleted |
|---|---|
| `restrict` (default) | the delete is refused while dependents exist |
| `cascade` | the dependents are deleted too |
| `nullify` | the dependents survive with the edge cleared |

`onDelete` governs the **dependent** side — whoever holds the key. `Post.comments` puts
`post_id` on comments, so deleting a Post applies the policy to its comments:
`restrict` refuses while any exist, `cascade` deletes them, `nullify` clears `post_id`
on them. Likewise `Inventory.item` puts `item_id` on inventory, so the policy decides
what happens to inventory rows when an Item goes.

On a many-to-many edge the policy applies to the rows linked through the join table.
`restrict` refuses while any are linked. `nullify` removes only the join rows for the
deleted row, leaving the far side and its other links untouched. `cascade` removes the
join rows and also deletes the far side, which for shared vocabulary is rarely what you
want.

Deletion is enforced by the framework, not by database foreign keys, and the semantics
are still due to be revisited. Say what you want in the spec rather than relying on the
default being right.

`inverse` is optional. `true` derives the reverse accessor name from the declaring
entity, lowercased — legal only when the reverse is unique, because the derived name is
singular and **the generator never pluralises**. For a non-unique reverse, name it:
`inverse: { name: posts, unique: false }`.

Declaring one generates the accessor on the target entity — `Comment::getPost()`, or
`Tag::posts()` returning a lazy query for a non-unique reverse — and exposes it in the
GraphQL manifest. Nothing is stored for it: an inverse is the same edge read from the
far end, so `Post.comments` remains the only relationship in the schema.

### Writing an edge

Reading and writing are symmetric, and the cardinality is in the signature:

```php
$inventory->setItem($itemId);          // cardinality: one — one identifier, or null
$post->comments()->add($commentId);    // cardinality: many
$post->comments()->set([$a, $b]);      // replace what it holds
```

Through the API an edge is a key in the input like any other — an id for a to-one edge,
a list of ids for a to-many one — and it **replaces** what the edge holds. Null or the
empty list clears it. Writing is always from the side that declares the edge; an
inverse is a read.

## Queries

```yaml
queries:
  published:
    args:
      limit: { type: int, nullable: true }
    returns:
      type: Post
      cardinality: many
    handler: true
```

Generates a method on `PostFinder` and an interface for you to implement. Collection
level only — traversing an edge is what `edges:` is for.

A query reaches the API only if it says so, separately from the entity's own exposure:

```yaml
    integrations:
      graphql:
        field: itemSearch
```

`cardinality: many` registers a connection — paging, cursors, `totalCount`; `one`
registers a plain field. The field is named rather than derived, because gluing a
plural to a query name produces `clogItemsLowStock`, which is what a generator writes
and not what a person would.

You do not need a query for "all of them": an exposed entity already gets a root
connection from its `plural`.

## Actions

```yaml
actions:
  publish:
    args:
      at: { type: datetime, nullable: true }
    writes:
      fields: [status, publishedAt]
      edges: [revisions]
    handler: true
```

The handler does **not** receive the mutator. It receives a context generated from
`writes:`, exposing only those members — so an action physically cannot touch a field
it did not declare, and PHPStan enforces it. Widening an action means editing the spec,
which is the point: blast radius is reviewable in the diff.

## SideEffects

```yaml
sideEffects:
  audit:
    on: [create, update]
    phase: postCommit
    handler: true
```

Declared in the entity spec and nowhere else. Reading the yaml tells you everything that happens on commit.

**Execution order is declaration order.** No priority numbers.

| phase | when | a throw | may mutate |
|---|---|---|---|
| `preCommit` (default) | after actions, before verification and storage | cancels before writes | yes — pending fields and relationships |
| `postCommit` | after `COMMIT` | logged; remaining sideEffects still run | yes — as a new unit of work |

A `postCommit` mutation is **not atomic** with the commit that caused it, and fires its
own sideEffects. Fine for audit trails and projections; never for an invariant.

## Policies

```yaml
policies:
  terminalRule:
    read: deny
    write: deny
readPolicies:
  signedIn:
    handler: true
writePolicies:
  staff:
    handler: true
```

Policies are ordered rules. Each returns `allow`, `skip` or `deny` with a reason; the
first non-skip result wins, so an `allow` ends evaluation: a later policy cannot restrict a
write an earlier one allowed. Put a restriction in the policy that allows, or have the
earlier policy return `skip`. Read policies gate single reads and filter denied collection
rows. Write policies gate create, update, delete and actions. `terminalRule` is an
entity-only fallback when all policies skip, and defaults to deny. An entity with no
policies is not gated, so the terminal rule is irrelevant and `count()` keeps its fast
path.

Read policies receive the entity and a `Viewer`. Entity write policies receive the
entity, generated `{Entity}WriteContext` and viewer. Pattern write policies receive the
shared runtime `WriteContext`, so one implementation can serve every entity using the
pattern. Policies declared by a pattern require `interface: true`; patterns may not
declare `policies.terminalRule`.

## Patterns

A pattern is a fragment of an entity spec — any section an entity may declare, it may
declare.

```yaml
pattern: Slugged
requires:
  driver: sqlite       # using it elsewhere is a compile error naming the reason
fields:
  slug: { type: string, unique: true, immutable: true, required: true }
```

### Configuration

A pattern may declare parameters each entity supplies:

```yaml
# patterns/Exportable.yml
pattern: Exportable
config:
  visibility:
    type: enum
    values: [public, private]
    default: private
  formats:
    type: list
    of: string
    default: []
```

```yaml
# entities/Post.yml
use:
  - Exportable
configure:
  Exportable:
    visibility: public
    formats: [csv, json]
```

The core validates values against the *pattern's own declaration* and has no idea what
any of them mean — which is what lets a pattern carry platform configuration without that platform
leaking into the framework.

Every applied pattern contributes into one map, so a consumer reads the keys it knows
rather than looking a pattern up by name. Two patterns declaring the same key is a
compile error, so a resolved value has exactly one source. Defaults are applied at
compile time; nothing downstream deals with absent keys.

`type: list` is allowed here and not for fields. Configuration is build-time data that
never becomes a column, so the reason a field cannot hold a list does not apply.

**Patterns are sealed.** An entity redeclaring a member a pattern defines is a hard
error — to change it, stop using the pattern. Overridable patterns would mean reading
one file no longer tells you what a field is.

Patterns may `use:` other patterns. Cycles are reported.

A pattern that declares read or write policies must declare `interface: true`, because
the generated policy contract is typed to that pattern's interface. Patterns may
contribute policies but cannot set the entity-level terminal rule.

## Types

```yaml
type: Money
primitive: int          # cents
processors: true        # → MoneyReadProcessor / MoneyWriteProcessor interfaces
```

```yaml
type: PostStatus
primitive: string
values: [draft, scheduled, published]
```

A type with `values:` is an enum and the generator owns the class outright. A type with
`processors:` names a value class **you** write; the generator only references it.

**No application namespaces appear in a spec.** `processors: true`, `handler: true`,
`verify: true` and policy declarations all say "generate the interface" — the namespace comes from
`eleph.json`, so renaming one is a config change rather than an edit to every entity.

## Canonical key order

`eleph fmt` enforces the order keys are written in, so a spec diff shows what changed
and nothing else. It reports rather than rewrites — PHP's YAML parsers discard
comments, and deleting an author's notes to fix an ordering nit is the wrong trade.

Order applies to **keys**, never to **members**: sideEffect declaration order is execution
order, so sorting members would change behaviour.

## Where patterns come from

The pattern catalogue is `patterns/` in the framework repository (`patterns/README.md`,
with a machine-readable `catalog.json`). You copy a pattern into your own `spec/patterns/`
and own the copy; nothing is fetched at build time. A builder can also ship patterns and
types of its own, which `describe` pools into the compiler.
