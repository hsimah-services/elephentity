# The spec format

**The JSON Schemas are the authority.** When this document and a schema disagree, the
schema is right.

```
vendor/elephentity/elephentity/packages/schema/resources/
  common.schema.json    shared definitions — fields, edges, queries, actions, triggers
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
  driver: wordpress
  tablePrefix: clog_
```

`driver` lives here rather than on each entity because a unit of work has one adaptor —
letting entities differ would invite a lie the format cannot honour. Pattern
`requires: { driver: … }` is checked against this, once.

`tablePrefix` is prepended to every entity's table and **stacks** with whatever the
driver adds: under WordPress the real table is `$wpdb->prefix` + this + the entity's
own `table`.

It is deliberately shallow. It may **not** declare patterns or fields applied to every
entity — that would mean reading an entity spec no longer tells you what that entity
has, which is the same reasoning that makes patterns sealed.

## Integrations

An integration exposes an entity to an external system. Distinct from a pattern, and
the difference is what they contribute: a pattern adds fields, edges and actions; an
integration adds no structure at all, only settings for the exposure.

Its permitted keys are declared **in PHP by the package providing it**, not as user
YAML — the keys are a property of the integration rather than a choice the project
makes. `eleph validate` checks against whatever is installed.

Opt-in twice. The project says what it speaks:

```yaml
# spec/project.yml
integrations:
  wpgraphql:            # or with settings: { rootQueries: false }
```

and each entity says whether it is exposed, and how:

```yaml
# spec/entities/Post.yml
integrations:
  wpgraphql:
    singular: ClogItem
    plural: ClogItems
```

An entity that says nothing is not exposed. Adding an entity should never silently
widen a public API, so the extra two lines are the point rather than a cost.

`wpgraphql` requires both names because nothing pluralises on your behalf — the same
rule as edge inverses, and "Inventory Entry" / "Inventory" is why.

## Entity

```yaml
entity: Post                      # PascalCase, matches the filename
description: A published article. # one line; real prose lives in the .md
use:                              # patterns to pull in
  - Timestamps
storage:
  table: post                     # snake_case; the project prefix is prepended
  handle: post                    # what the storage system calls it
fields: { }
edges: { }
queries: { }
actions: { }
triggers: { }
```

`handle` is driver-agnostic — a WordPress post type slug here, a collection name
elsewhere — but validated per driver. Under `wordpress` it must be lowercase and at
most 20 characters, because WP silently truncates longer slugs at registration.

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
    maxLength: 200      # string only; default 255
    values: [a, b]      # enum only — a list, or the name of a declared enum
    verify: true        # generate an entity-specific verifier interface
```

**`required` and `nullable` are different facts.** `required` is about creating a row;
`nullable` is about what the column can hold. All four combinations are meaningful, and
conflating them is the most common mistake in a first spec.

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
    onDelete: restrict
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

`inverse` is optional. `true` derives the reverse accessor name from the declaring
entity, lowercased — legal only when the reverse is unique, because the derived name is
singular and **the generator never pluralises**. For a non-unique reverse, name it:
`inverse: { name: posts, unique: false }`.

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
      wpgraphql:
        field: clogItemSearch
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

## Triggers

```yaml
triggers:
  audit:
    on: [create, update]
    phase: postCommit
    handler: true
```

Declared in the entity spec and nowhere else. That is the difference between this and
WordPress hooks: reading the yaml tells you everything that happens on commit.

**Execution order is declaration order.** No priority numbers.

| phase | when | a throw | may mutate |
|---|---|---|---|
| `preCommit` (default) | inside the transaction, after the flush so ids exist | rolls back everything | no |
| `postCommit` | after `COMMIT` | logged; remaining triggers still run | yes — as a new unit of work |

A `postCommit` mutation is **not atomic** with the commit that caused it, and fires its
own triggers. Fine for audit trails and projections; never for an invariant.

## Patterns

A pattern is a fragment of an entity spec — any section an entity may declare, it may
declare.

```yaml
pattern: WordPressPost
requires:
  driver: wordpress    # using it elsewhere is a compile error naming the reason
fields:
  postId: { type: int, unique: true, indexed: true }
```

### Configuration

A pattern may declare parameters each entity supplies:

```yaml
# patterns/WordPressPost.yml
pattern: WordPressPost
requires:
  driver: wordpress
config:
  visibility:
    type: enum
    values: [public, private]
    default: private
  supports:
    type: list
    of: string
    default: []
```

```yaml
# entities/Post.yml
use:
  - WordPressPost
configure:
  WordPressPost:
    visibility: public
    supports: [title, editor]
```

The core validates values against the *pattern's own declaration* and has no idea what
any of them mean — which is what lets a WordPress pattern carry WordPress configuration
without WordPress leaking into the framework.

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

**No application namespaces appear in a spec.** `processors: true`, `handler: true` and
`verify: true` all say "generate the interface" — the namespace comes from
`eleph.json`, so renaming one is a config change rather than an edit to every entity.

## Canonical key order

`eleph fmt` enforces the order keys are written in, so a spec diff shows what changed
and nothing else. It reports rather than rewrites — PHP's YAML parsers discard
comments, and deleting an author's notes to fix an ordering nit is the wrong trade.

Order applies to **keys**, never to **members**: trigger declaration order is execution
order, so sorting members would change behaviour.
