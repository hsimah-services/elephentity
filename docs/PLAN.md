# Elephentity — Architecture Plan

An AI-native PHP framework that compiles human-readable specs into locked-down,
deterministic business-logic code.

> This document is the running record of design decisions. Every decision below has
> been agreed unless marked **[proposed]** (suggested, not yet confirmed) or listed
> under Open Questions.

---

## 1. Core premise

Consistency comes from **determinism**, not from linting. Anything a generator can
derive from the spec is generated; agents and humans only write the parts where no
deterministic answer exists — and even those are constrained by generated, typed
interfaces that static analysis can prove against.

Generated files are **locked**. Users may never edit them. Editing one is a build
failure, not a convention.

---

## 2. The pipeline

```
  conversation ──▶ entity.md ──▶ entity.yml ──▶ PHP ──▶ signed ──▶ commit ──▶ CI
      (LLM)          (LLM)        (build)      (build)   (md+yml+php)   (verify)
```

1. Talk to an LLM; produce a markdown description of the object and its use.
2. LLM generates a YAML schema file of a known format from the md.
3. LLM or human runs the build script; schema is compiled to PHP, clobbering the
   previous output.
4. The build script signs each generated file.
5. All three artifacts (md, yml, php) are committed together.
6. CI verifies signatures, regenerates and diffs, runs static analysis and tests.

### Source of truth

The **YAML is authoritative** from the moment it first exists. The md is the record
of intent — what the object is and why — and stays committed and reviewed alongside,
but is not re-compiled wholesale (the md → yaml step is the only non-deterministic
one in the chain; re-running it would churn unrelated fields and clobber hand
corrections).

### md/yaml drift

Accepted as an unsolved human problem, same class as API docs going stale. Mitigated,
not fixed, by an **advisory** agentic check that runs on the PR (not post-merge, so
it lands while the author is still looking at the diff). The agent is given narrow,
mechanical questions — "list fields in the yaml with no counterpart in the md, and
statements in the md describing behaviour absent from the yaml" — rather than "are
these aligned?", because enumerations are checkable and opinions are not.

---

## 3. Layer stack

```
spec (.md + .yaml)
      │
      ▼
Schema IR ─────────────┬──────────────┬────────────────┐
  parse, validate,     │              │                │
  resolve patterns     ▼              ▼                ▼
                   codegen        migrations      wpgraphql manifest
                       │
                       ▼
              Entity / Mutator / Finder
                       │
                       ▼
                 StorageAdaptor (port)
                       │
                       ▼
                WordPress adapter
```

The **Schema IR** sits between the spec and every consumer. Without it, the entity
generator and the GraphQL generator each grow their own half-answer to "what does a
spec field mean", and drift from each other rather than from the spec.

### Package boundaries

| Package | Responsibility |
|---|---|
| `schema/` | spec parser, JSON Schema, pattern resolution, IR, semantic validation |
| `codegen/` | IR → PHP (entity, mutator, finder, handler interfaces, migrations) |
| `runtime/` | StorageAdaptor port, capabilities, unit of work, loaders, verification |
| `wordpress/` | the one adapter — the **only** package allowed to name `WP_*` |
| `wpgraphql/` | IR → compiled registration manifest |
| `cli/` | `eleph generate` / `validate` / `check` / `migrate` |

### The project spec

`spec/project.yml` is required, and holds what no entity can sensibly vary:

```yaml
project: Clog
storage:
  driver: wordpress
  tablePrefix: clog_
```

**The driver moved off entities.** It was identical on every one and always would be —
a unit of work has one adaptor — so repeating it was noise that also implied entities
could differ, a lie the `requires:` mechanism then had to guard against. Now
`requires: { driver: … }` resolves once against the project.

**The table prefix is applied at compile time**, so conflict detection, DDL and queries
all see one resolved name rather than each remembering to prepend it. It stacks with
the driver's own prefix.

**Explicit non-goal: the project spec may not declare patterns or fields.** It is the
obvious next request — "every entity gets Timestamps" — and it would mean reading an
entity spec no longer tells you what that entity has. That is the same reasoning that
makes patterns sealed, and it is recorded here so it stays decided.

`eleph.json` stays separate. It holds paths and namespaces, and keeping application
namespaces out of *specs* is load bearing; the division is that `eleph.json` says where
output goes and `project.yml` says what the project is.

### Packaging: a Composer library

Elephentity ships as a Composer library consumed by a thin WordPress plugin — **not** as a
WP plugin itself. Shipping the framework as a plugin would bake WordPress into the
distribution model of a thing whose whole premise is that WP is one adapter among
several, making the abstraction nominal.

The packages split cleanly by lifecycle:

- **Dev-only, build time:** `schema`, `codegen`, `cli` — never ship to production.
- **Runtime, shipped:** `runtime`, `wordpress`, `wpgraphql`.

**Known risk.** WordPress has no dependency manager and no class isolation — every
plugin loads into one shared PHP process, and PHP class names are global. If two
plugins each bundle their own `vendor/` with a different Elephentity version, whichever
autoloads first wins and the other silently gets the wrong classes. Accepted for now
because we are the only consumer; the fix, if Elephentity is ever distributed widely, is
[php-scoper](https://github.com/humbug/php-scoper) to rewrite dependencies under a
private namespace prefix at build time.

### Storage port

The storage port is defined **now**, with exactly one adapter, rather than retrofitted
later — WP concepts (int post IDs, postmeta as untyped KV, taxonomies-as-edges,
`WP_Query` semantics) leak quietly otherwise. Enforced statically: no `WP_*` symbol
outside `wordpress/`.

Adaptors **declare capabilities** (transactions, full-text, faceting) rather than the
port flattening to a lowest common denominator.

---

## 4. Generated code and locking

### What is generated

Everything mechanical: field getters, setters, edge accessors, finders, storage
mapping, handler interfaces, GraphQL registration, migrations.

### What is hand-written

Only implementations of generated interfaces — custom queries, actions, field
verifiers. The spec declares the bespoke unit; the generator emits its exactly-typed
interface; a human or agent writes the class.

**Spec-first is therefore mandatory, not encouraged.** A new custom query needs a spec
entry before the code can exist.

### Failure mode

**Boot-time.** The container wires handlers on startup; a missing implementation
throws immediately, before any request is served, and the error names every missing
handler at once. Codegen stays a pure function of the spec (it never scans `src/`),
and "the app won't start" is as hard a failure as we need.

### Signing

Each generated file carries a fixed-length header containing a hash of `path +
content`, where the hash covers **everything after the header** (a fixed line count is
excluded — a file cannot hash its own hash). One shared verifier owns the line count.

No sidecar manifest. This gives up hash-based detection of *added* or *deleted* files
in the generated tree, but `generate --check` — regenerate into a temp tree and diff —
catches both, so nothing is lost.

Verification runs **as part of the build process** / CI, not per-request at runtime.

---

## 5. Spec format

YAML, one file per entity, validated against a JSON Schema.

```yaml
entity: Post
description: A published article.     # one line; real prose lives in the md
use:
  - Timestamps
  - WordPressPost
storage:
  driver: wordpress
  table: phe_post
  handle: post          # WP post type slug; a collection name on other drivers
fields:   { ... }
edges:    { ... }
queries:  { ... }
actions:  { ... }
```

`handle` is deliberately driver-agnostic — other adaptors may use it for something
else — while the compiler applies **driver-specific validation** to it (with
`driver: wordpress`, a post type slug must be lowercase and ≤ 20 chars, so
`handle: ProductVariation` fails at compile time rather than being silently truncated
by WP at registration).

### Patterns (reusable config)

Cross-file reuse via our own `use:` directive, resolved by the schema compiler before
IR construction. **Not** YAML anchors or merge keys — those are file-local and only
deduplicate within a single entity.

- **Sealed.** An entity declaring a field a pattern already defines is a hard error.
  Overridable patterns would mean reading one file no longer tells you what a field
  is, which is precisely the drift the framework exists to prevent.
- Patterns may declare `requires: { driver: wordpress }`, so using a platform-specific
  pattern on the wrong driver is a compile error naming the reason, rather than
  generated code that cannot work. This is where capability checking lands.
- **Patterns may be configured.** A pattern declares parameters with `config:`; each
  entity supplies them with `configure:`. The compiler validates values against the
  pattern's own declaration, so a WordPress pattern can carry WordPress configuration
  without the core learning what `visibility` means. Every applied pattern contributes
  into one map — a consumer reads the keys it knows rather than looking a pattern up by
  name, which would mean renaming a pattern silently disabled what depended on it. Two
  patterns declaring the same key is a compile error.

  `type: list` is permitted in configuration though not in fields: configuration is
  build-time data and never becomes a column, so the reason fields cannot hold one does
  not apply.

- **A pattern is a fragment of an entity spec.** Any section an entity may declare, a
  pattern may declare: fields, edges, storage, queries, actions and triggers. Sealed
  collisions apply uniformly, with no per-section special cases.

  This is more powerful than it first appears. A `Publishable` pattern can carry a
  `publish` action, and every entity using it gets its own generated
  `PostPublishAction` / `PagePublishAction` interface to implement — consistent
  behaviour, entity-specific logic. The cost is that reading `Post.yml` alone tells
  you less; you follow the `use:` list. That is already true of fields, and `use:` sits
  at the top of the file.

```yaml
# patterns/WordPressPost.yml
pattern: WordPressPost
requires:
  driver: wordpress
fields:
  post_id: { type: int, indexed: true, unique: true }
```

`WordPressPost` provides the WP-specific extras; the entity still declares its own
`handle`. A `FacetWP` pattern is the intended home for facet integration later — same
mechanism, no special case in the core.

---

## 6. Type system

A **closed** set of primitives, so every mapping (SQL column, PHP type, GraphQL type,
validation) is a lookup table in the generator with no escape hatch:

`string`, `text`, `int`, `float`, `bool`, `datetime`, `id`, `enum`, `json`

### Declaration

Domain types are value types aliasing a primitive. **One file per type in `types/`**,
matching `entities/` and `patterns/` — same discovery convention, and a type gets its
own commit history, which matters now that the spec is the changelog.

```yaml
# types/Money.yml
type: Money
primitive: int          # cents
processors: true        # → MoneyReadProcessor / MoneyWriteProcessor interfaces
```

**No application namespaces in the spec.** `processors: true` follows the same rule as
`verify: true` and `handler: true`: the generator emits the interfaces, boot fails
until both are implemented. Fully-qualified names are resolved from a root namespace
configured once in `eleph.json` — configuration, not specification — so a class rename
is never a spec edit.

### The value class is user-owned

The generator does **not** emit `Money`. A value object has real behaviour
(`add()`, `allocate()`, `format()`) that no generator can invent, so it stays plain,
unconstrained PHP written by hand.

The framework only requires that it exists: a missing value class fails boot by name,
same as a missing handler, and PHPStan catches it earlier still because generated
signatures type-hint it.

### Null short-circuits

A nullable field holding null **never reaches** `read()`, `verify()` or `write()`.
Null in, null out. Otherwise every processor ever written opens with the same null
check.

### Processors

Mirrors the Entity/Mutator split at the type level — same shape all the way down.

```php
/** @template TIn @template TOut */
interface ReadProcessor {
    /** @param TIn $value @return TOut */
    public function read(mixed $value): mixed;
}

/** @template TIn @template TOut */
interface WriteProcessor {
    /** @param TOut $value */
    public function verify(mixed $value, MutationContext $context): Verification;
    /** @param TOut $value @return TIn */
    public function write(mixed $value): mixed;
}
```

### Enums

The one type the generator owns outright — an enum is pure data, so it emits a locked
PHP 8.1 backed enum with no user code involved.

`values:` accepts either an array or the name of a declared enum:

```yaml
values: [draft, scheduled, published]    # inline; generates PostStatus
values: PostStatus                       # references types/PostStatus.yml
```

- An inline enum derives its class name from **entity + field**, so an inline
  `Post.status` colliding with a declared `types/PostStatus.yml` is a **compile
  error**, not a silent overwrite.
- Both forms generate the **same FQN**, so promoting an inline enum to a file is a
  no-op in generated code — move three words into `types/`, regenerate, nothing
  downstream changes. Free refactoring path for when a second entity wants the same
  enum.

### String sizing

`string` becomes `VARCHAR(n)`, defaulting to **255**, overridden per field with
`maxLength`.

**Indexed strings are checked against the driver's index limit.** MariaDB with DYNAMIC
row format allows 3072 bytes per index and utf8mb4 is 4 bytes per character — 768
characters. Exceeding it is a **compile error**, never a silent prefix index: the
WordPress tradition of quietly indexing the first 191 characters is a trap, because
with `unique: true` a prefix index enforces uniqueness *of the prefix*, so two
genuinely different values collide and nothing tells you.

**Past a ceiling, the answer is `text`, not a bigger number.** The compiler rejects an
oversized `maxLength` with "use `text`" rather than letting VARCHAR creep toward the
65,535-byte row limit, where the failure arrives much later and is far more confusing.

---

## 7. Verification

### Violations are returned, never thrown

Throwing means the first invalid field aborts and the caller fixes one error per round
trip — painful when a GraphQL mutation submits fifteen fields. Returning lets the
Mutator run every verify up front and reject the whole unit of work with a complete
list.

```php
final class Verification {
    public static function ok(): self;
    public static function failed(Violation ...$violations): self;
}

final class Violation {
    public function __construct(
        public readonly string $code,      // 'money.negative'
        public readonly string $message,
    ) {}
}
```

A processor does not know which field it is attached to (`Money` is reused across many
entities), so it returns code and message only; the **Mutator attaches the field
path** as it aggregates. `failed()` is variadic so a single rule can fail in several
ways at once.

### MutationContext

Passed as the second argument to every `verify`. Holds:

- the **old value** — the object being mutated as it was when the mutation started
  (null on create; processors must handle that)
- a **property bag of new/mutated fields**

so a verifier can inspect original values, or other fields' new and old values. Every
verify runs against the **same final pending state**, not incrementally as setters
fire — so ordering does not matter and two fields can validate against each other
symmetrically.

Values in the bag are domain-typed (`TOut`) on both sides: `write()` has not run yet
at verify time, and old values come from the Entity.

### Two tiers

1. **Field verifier** — entity-specific, runs first, and can be exactly typed because
   the generator knows both the entity and the field's domain type:

   ```php
   interface PostPriceVerifier {
       public function verify(Money $value, PostMutationContext $ctx): Verification;
   }
   ```

2. **Type verifier** — the shared `WriteProcessor::verify`, generic across entities,
   which is where unavoidable `mixed` access (`$ctx->new('price')`) lives.

Both tiers always run even when the first fails, so violations aggregate into one
response.

Field verifiers are declared `verify: true` — the generator emits the interface and
boot fails until something implements it, exactly like queries and actions. The spec
never carries application namespaces, so a class rename is not a spec edit.

---

## 8. Fields

```yaml
fields:
  price:
    type: Money
    required: true      # must be supplied on create
    nullable: false     # column allows NULL
    default: 0
    unique: false
    indexed: true
    immutable: false    # write-once: settable on create, no setter after
```

`required` and `nullable` are deliberately separate facts; all four combinations are
meaningful.

### Identity

Every entity has an `id`, implicitly — no pattern needed, no way to override.

**BIGINT auto-increment for now.** WP-standard and unblocking; the alternative
(UUIDv7 as `BINARY(16)`, time-ordered so it indexes nearly as well as an int,
generatable before the row exists) is better for the unit of work but is a fight with
the platform we do not need yet.

To keep that refactor cheap, the ID is **opaque above the storage layer** — a value
object in PHP, a string in GraphQL. Nothing outside the adaptor does arithmetic on it
or assumes it is numeric.

**Consequence:** server-generated IDs mean a commit that creates a Post *and* its
Comments must insert the Post, read back its ID, then insert the Comments. The unit of
work therefore **orders writes by dependency** rather than firing a flat batch. Built
in from the start; retrofitting ordering into a flush loop is unpleasant.

---

## 9. Edges

```yaml
edges:
  comments:
    to: Comment
    cardinality: many
    inverse: true                 # reverse is unique: one-to-many
  tags:
    to: Tag
    cardinality: many
    inverse: { name: posts, unique: false }   # many-to-many
```

`cardinality` describes the forward side; `inverse.unique` describes the reverse.
Together they determine the relation, and therefore storage:

| `cardinality` | `inverse.unique` | relation | storage |
|---|---|---|---|
| `one` | `true` | one-to-one | foreign key here, unique |
| `one` | `false` | many-to-one | foreign key here |
| `many` | `true` | one-to-many | foreign key on the far side |
| `many` | `false` | many-to-many | join table |

**Relation storage is inferred, never declared.** If the generator can work it out, a
human choosing it is a chance for two entities to disagree.

Describing the reverse side this way rather than adding a `manyToMany` cardinality
keeps each key describing one direction, and it buys one-to-one, which the format
could not previously express.

### Inverses are optional and opt-in

- `inverse: true` — generate the reverse accessor, name derived from the declaring
  entity lowercased (`Post.comments` → `Comment::getPost()`). `unique` defaults to
  true.
- `inverse: post` — shorthand for `{ name: post }`.
- `inverse: { name: posts, unique: false }` — the full form.
- A derived name with `unique: false` is a **compile error**: the derived name is
  singular, and a to-many reverse would have to be pluralised.

The generator never pluralises. English inflection quietly produces `Categorys` and
different libraries disagree; that is not acceptable in a framework whose selling
point is predictable output.

### To-many returns a lazy edge query, not an array

```php
$post->comments()->count();
$post->comments()->page(limit: 20, after: $cursor);
$post->comments()->all();          // explicit, so unbounded loads are visible
```

Three reasons: unbounded loads become a deliberate `->all()`; GraphQL connections map
onto it directly instead of a resolver slicing an already-hydrated array; and the
loader can **batch across a result set** — one query for the comments of fifty posts
instead of fifty. That last is the N+1 defence and is very hard to add once code
everywhere assumes an array.

### Deletion — provisional

Not a current priority; to be shored up when deletion is actually implemented.

- `onDelete: restrict | cascade | nullify`, defaulting to `restrict` so orphaning
  data takes a deliberate keystroke.
- **Framework-enforced**, so errors are good and actions/logging still run.
- A `before_delete_post` hook in the adaptor catches out-of-band WP deletes (someone
  empties the trash, another plugin calls `wp_delete_post()`) — no framework-level
  enforcement can see those.
- **No real foreign keys for now.** `dbDelta()` does not understand FK constraints, so
  emitting them takes us fully off the WP path.

---

## 10. Queries, actions and triggers

### Queries — collection-level finders

Edge traversal is covered by the edges section, so `queries:` is for finders.

```yaml
queries:
  inCategory:
    args:    { categoryId: { type: id } }
    returns: { type: Post, cardinality: many }
    handler: true
```

Generated into an injectable **`PostFinder`**, not as statics on the Entity —
statics are awkward to inject into and to fake in tests, and it keeps the Entity
exactly one thing (a single-row read model) rather than also a collection gateway.
**[proposed]**

Finders return the same lazy query object edges return, so `->page()` and `->count()`
work identically however you got there.

### Actions

A named write operation on the Mutator — `publish`, `approve`, `transferOwnership` —
that sets fields, writes edges, and commits as one unit.

```yaml
actions:
  publish:
    args:  { at: { type: datetime, nullable: true } }
    writes:
      fields: [status, publishedAt]
      edges:  [revisions]
    handler: true
```

Generates `PostMutator::publish(?DateTimeImmutable $at)` delegating to a
`PostPublishAction` interface.

#### Declared blast radius

An action does **not** receive the Mutator. It receives a **narrow context** generated
from its `writes:` block, exposing only `setStatus()`, `setPublishedAt()` and
`revisions()->add()`. An action physically cannot touch a field it did not declare,
and PHPStan enforces it.

This makes blast radius reviewable in the yaml rather than discoverable only by
reading the implementation. Widening an action is a spec edit and a regeneration —
which is the point, not the cost (see *The spec as changelog* below).

There is no `writes.entities`. Cross-cutting side effects are triggers, not actions.
Clean line: **actions are this entity's business operations; triggers are what happens
on commit.**

### Triggers

Classes that receive the mutation context and run as part of the commit.

```yaml
triggers:
  audit:
    on: [create, update]
    phase: preCommit        # default
    handler: true           # → PostAuditTrigger
  reindex:
    on: [create, update]
    phase: postCommit
    handler: true
```

#### Declared in the entity spec, never registered elsewhere

This is the whole difference between triggers and WordPress hooks. If a trigger could
be registered anywhere, reading `Post.yml` would no longer tell you what a commit
does, and debugging becomes "grep for anything that might fire". Registration lives in
the spec; only the implementation is a class.

Reuse without copypasta is already solved by patterns: `use: [Auditable]` and the
pattern carries the trigger.

#### Ordering

**Declaration order in the yaml.** Deterministic, visible in the diff, and no
`add_action($hook, $fn, 10)` priority-number archaeology.

#### Phases — precise semantics

| Phase | When | A thrown exception |
|---|---|---|
| `preCommit` | inside the transaction, **after** the entity's writes are flushed, before `COMMIT` | rolls back the entire commit |
| `postCommit` | after `COMMIT`; data is durable | logged; remaining triggers still run |

`preCommit` deliberately runs *after* the flush, not before it: with auto-increment
IDs a create trigger that ran earlier would have no ID to work with. This way the row
exists, the ID is real, and a throw still rolls everything back.

Anything slow or external (email, HTTP, search indexing) belongs in `postCommit` —
holding DB locks while calling a third party is how transactions die.

#### Failure handling is the trigger's job

The framework does not classify triggers as critical or not. Throw to abort, swallow
to continue. The trigger knows; the framework shouldn't guess.

#### Mutation is allowed in `postCommit` only

| Phase | May mutate? | Why |
|---|---|---|
| `preCommit` | **No** | keeps the in-transaction path a single pass — no cascades, no re-triggering, no cycle detection. It is a veto-and-observe phase. |
| `postCommit` | **Yes** | the transaction is already closed, so a write here is simply a *new* unit of work rather than an extension of the current one. |

This is what makes audit logging work: an `AuditLog` **entity** is written by a
`postCommit` trigger like any other entity, with no special framework support and no
writing below the entity layer.

Two properties of a `postCommit` mutation to be aware of:

- **It is not atomic with the commit that caused it.** It can fail after the original
  succeeded. Fine for audit trails, denormalised counters and projections; never use
  it to enforce an invariant.
- **It is an ordinary commit, so it fires its own triggers.** Genuine cascades are
  therefore possible (`Post` → writes `Comment` → whose `postCommit` writes `Post`).
  Nothing detects that today; a depth limit is the obvious guard if it bites.

### The spec as changelog

The yaml is the record of the entity's history — widening an action or adding a
trigger shows up in a commit diff, which is the intent.

That makes diff noise a real cost, so a canonical formatter (`eleph fmt`, enforced in
CI) keeps key order and style stable and every diff semantic, rather than recording
whichever whitespace the LLM felt like that day.

---

## 11. Runtime model **[proposed]**

- **Entity** — immutable hydrated snapshot; edges lazy-loaded through a per-request
  batching loader.
- **Mutator** — a command buffer accumulating operations, flushed in `commit()`. That
  *is* the unit of work; "actions" are named, individually testable methods on it
  rather than a grab-bag.

---

## 12. Storage: WordPress adapter

**Custom tables, WP-native where needed.** Real typed columns and indexes for entity
fields; register a post type only where WP ecosystem integration (FacetWP, admin,
permalinks) actually requires it.

### Migrations — a layer custom tables force on us

DDL is generated deterministically from the schema, but a diff of two schema versions
cannot infer intent: `title` disappearing while `heading` appears is either a rename
or a drop-plus-add, and guessing destroys data.

- Additive changes (new nullable column, new index) → generated and auto-applied.
- Destructive or ambiguous changes → **generation fails** and demands an explicit,
  checked-in migration file.

Our own migration runner, not `dbDelta()`.

### Post-row divergence

When a post type is registered, the custom table row and the post row are two records
that can diverge. The **custom table is authoritative**; the post row is a projection
the Mutator writes as part of the same unit of work.

---

## 13. Plugin layer: WPGraphQL

Registration is driven by a **compiled manifest generated at build time** and read at
runtime — rather than generating resolver PHP (which can drift) or walking the IR
reflectively on every request (runtime cost, no static analysis).

Convention: spec field `title` → `getTitle()` on the read object → GraphQL field
`title`.

---

## 14. Verification gates (CI)

Cheapest first, each catching a distinct class of failure:

1. **`validate`** — spec well-formed against the JSON Schema, and semantically closed
   (every edge target resolves, every inverse is legal, every pattern's `requires` is
   satisfied).
2. **`generate --check`** — regenerate and diff; zero tolerance. Also catches added and
   deleted files in the generated tree.
3. **Signature check** — header hashes match content.
   Also `eleph fmt --check`, so spec diffs stay semantic.
4. **PHPStan at max** — on both generated and hand-written trees. Generated code should
   be typed well enough that PHPStan can prove the plugin layer correct.
5. **Architecture rules** — no `WP_*` outside `wordpress/`; Entity never writes;
   Mutator never returns an Entity.
6. **Conformance tests** — every spec field reaches a getter and a GraphQL field.
7. **Advisory md/yaml alignment agent** — non-blocking PR comment.

---

## 15. Implementation steps

### Step 0 — Foundations
Repo layout, Composer packages and autoloading, CI skeleton, PHPStan config.

**PHP 8.3 is the floor.** It is WordPress.org's own recommended line, it holds
security support into 2027 (8.2 loses it in December 2026), and it is universally
available on managed WP hosts. It supplies everything the design needs: native backed
enums (8.1) for generated enum classes, `readonly` properties (8.1) and readonly
classes (8.2) for immutable entity snapshots, and typed class constants plus
`#[\Override]` (8.3), the latter genuinely useful where hand-written classes implement
generated interfaces.

Not 8.4, despite it being the newer recommendation: host support is thinner, and its
two most attractive features for us — property hooks and asymmetric visibility — are
moot because the WPGraphQL layer maps spec field → `getField()`, so we are committed
to explicit accessors regardless. Everything else 8.4 offers is internal and reachable
later by regenerating.

Every generated file emits `declare(strict_types=1)`, and a lint rule requires it in
hand-written code.

### Step 1 — `packages/schema`
The keystone; everything is downstream.
- JSON Schema for the spec format
- YAML parser and loader
- `use:` pattern resolution with sealed-collision errors and `requires:` checking
- IR construction (entities, fields, types, edges, queries, actions)
- Semantic validation: edge targets resolve, inverse legality, driver-specific rules
  (e.g. `handle` constraints), no dangling type references
- `eleph validate`

### Step 2 — `packages/runtime` contracts
No implementations — just the shapes everything else compiles against.
- `StorageAdaptor` port, speaking only in entity names and primitives
- `Capability` / `Capabilities`, declared per adaptor rather than flattened to a
  lowest common denominator
- `Verification`, `Violation`
- `ReadProcessor`, `WriteProcessor`, `ProcessorRegistry`, `MutationContext`
- `EntityQuery`, the lazy edge query every to-many accessor and finder returns
- `Identifier`, split into `EntityId` (persisted, opaque) and `PendingId`

**Two identity states, not one.** A commit creating a Post *and* its Comments needs to
refer to the Post before the database has assigned it an id, so both states satisfy
`Identifier` and the unit of work resolves each `PendingId` to a real `EntityId` as its
target is flushed. `PendingId` compares by object identity, because there is nothing
else yet to compare.

**`MutationContext` uses `original()` and `pending()`**, not `new()` — `new` is a
reserved word, and while PHP permits it as a method name it reads badly at the call
site. `pending()` falls back to the original for untouched fields, so a verifier always
sees what the row will actually hold.

### Step 3 — `packages/codegen`
- Entity, Mutator, Finder, action contexts, typed mutation contexts, enums
- Handler interfaces: queries, actions, triggers, field verifiers, type processors
- Header + hash signing, with the single shared verifier
- `eleph generate`, `eleph generate --check`, driven by `eleph.json`

**`--check` catches all three ways a tree drifts:** a hand-edited file (digest
mismatch), a stale file the schema no longer produces, and a deleted one. That is why
no sidecar manifest is needed to track the tree's contents.

**Paths in the digest are relative.** An absolute path would make every signature
depend on where the project happens to be checked out.

**Everything for one entity lives in one folder.** `Item/` holds the entity, mutator,
finder and bridges, and `Item/Contract/` holds exactly the interfaces someone must
implement before the application boots — so "what do I owe this entity?" is answered by
listing a directory. Enums stay in `Enum/` regardless of whether they were declared or
inline, because that shared placement is what makes promoting an inline enum a no-op;
type processors stay in `Type/`, since Money belongs to no single entity.

**What generated code builds is sealed behind a named constructor**; what a container
builds is not. Entities and contexts get `Item::of(...)` with a private constructor —
`new Item(...)` beside `Item::of(...)` says nothing about which is intended, and the
runtime already reads this way (`EntityId::of()`, `Verification::ok()`).

Mutators, finders, hydrators and bridges keep public constructors, because every
mainstream container autowires through one. Sealing them would buy uniformity at the
price of an explicit service definition per entity, forever. The line is "who
instantiates this", which is crisp enough to apply without thinking.

**Interfaces extend nothing.** PHP forbids narrowing a parameter type in an
implementation, so a common base declaring `verify(mixed, MutationContext)` would make
`PostPriceVerifier::verify(Money, PostMutationContext)` illegal. Generated callers know
the concrete type and call it directly, which is what keeps the typing exact.

### Step 4 — `packages/wordpress`
- `SchemaBuilder`: the spec's physical schema — columns, indexes, edge placement
- `QueryCompiler`: a Criteria as a parameterised SELECT
- `MigrationPlanner`: the diff, split into what can be applied and what cannot
- `WordPressAdaptor`: dispatch and the transaction boundary, over a narrow `Database`
- `PostTypeRegistrar` and `OrphanGuard`

**The adaptor is deliberately thin.** Everything worth getting right — how a spec
becomes a schema, how a Criteria becomes SQL, what a migration may do unattended — is
a pure class testable without a database. What remains in the adaptor is dispatch.

**Identifiers are resolved, not escaped.** A value always becomes a placeholder, but a
column name cannot be parameterised, so the query compiler resolves field names against
the table's own schema and refuses anything it does not find. An unknown column is a
bug in the caller, and refusing beats quoting whatever arrived.

**Enum columns are sized to their longest member**, from the spec rather than a fixed
width, so adding a longer member surfaces as a migration instead of being absorbed.

**PHPStan needs the WordPress stubs**, which are large enough to exhaust the default
128M — hence `--memory-limit=1G` on the `stan` script. The architecture rule still
keeps those symbols out of the core packages.

### Step 5 — Unit of work
- `Mutation`: the command buffer and the context, two views of one state
- `VerificationPipeline`: both tiers, aggregated
- `DependencySorter`: parents before the children that reference them
- `UnitOfWork`: verify → sort → rows → links → preCommit → COMMIT → postCommit
- `LazyEntityQuery` and `CachingEdgeLoader` on the read side

**Verification happens before anything is written.** A rejected commit leaves no
partial state and reports every violation at once, which is the whole reason verifiers
return violations rather than throwing.

**Links are a port concept, not a SQL one.** `Link` and `Unlink` say what the
relationship is; whether that becomes a foreign key update or a join-table row depends
on the relation, and only the adaptor knows the physical schema. `EdgePlanner` decides
placement once, and both the schema builder and the query compiler read it — two
derivations would be two chances to disagree about the same edge.

**The generated bridges close the loop.** The runtime must call a verifier and a
trigger polymorphically, but the interfaces the application implements take concrete
types — `verify(Money, PostMutationContext)` — and PHP forbids narrowing a parameter,
so no shared base could declare them. Generated code is allowed to know both sides:
`PostVerifiers`, `PostTriggers` and `PostHydrator` take `mixed`, narrow with an assert,
wrap the context, and dispatch. The user's interface stays exactly typed and the
runtime stays generic.

Coercion rules live in `ValueDecoder` and `ValueEncoder` rather than being emitted into
every hydrator: they are identical for every entity, and testing them once beats
generating fifty copies of the same `is_string` check.

**Field names never reach SQL.** `FieldMap` translates between the spec's field names
and the database's columns, in the adaptor and nowhere else. Everything above speaks in
fields; everything below in columns.

**Laziness is free; batching is not.** An edge accessor returning a query costs nothing
until asked. But once fifty posts have each been asked for their comments one at a
time, fifty queries have run — batching cannot be retrofitted onto calls already made.
It needs a caller holding every id up front, which a GraphQL resolver has and a
getter does not. Hence `preload()`, called explicitly, with an identity map covering
the repeat-access case in between.

### Step 6 — `packages/wpgraphql`
- `ManifestBuilder`: schema → the whole API surface, pure and therefore tested
- `ManifestExporter`: the manifest as PHP source, emitted by `eleph generate`
- `TypeRegistrar`: the thin translation into WPGraphQL's arrays
- `ConnectionResolver`: connections over the lazy query, and where `preload()` is called
- `ConformanceChecker` and `eleph check`

**A manifest of constructor calls, not nested arrays.** The file type-checks like any
other code, so a manifest that no longer matches its value objects fails at build time
rather than on the first request — and a diff reads as a description of the API
surface, which is what a reviewer wants when a spec changes.

**A value type travels as its backing primitive.** Money is an `Int` over the wire. A
custom scalar would need serialise and parse functions the spec does not carry, and
would insert a second, unvalidated conversion between the client and the write
processor that already owns that job.

**`required` says nothing about reading.** A field is non-null in the GraphQL output
when the *spec* says it is not nullable; `required` describes creating a row, so it
shapes the create mutation's inputs and nothing else. An immutable field is present on
create and absent from update — the same rule the mutator enforces by generating no
setter.

**`eleph check` is a different question from `generate --check`.** One asks whether the
tree matches the spec; the other asks whether the tree is *coherent* — that every field
the API exposes resolves to a method that exists. It loads the generated classes with
its own PSR-4 autoloader rather than the project's, because relying on the project's
Composer configuration would make the gate pass silently whenever that configuration
was wrong, which is exactly when it should fail.

### Step 7 — Verification and CI gates
- `eleph fmt`, the canonical key-order gate
- An end-to-end pipeline test running all four gates in order
- `docs/CI.md`, the workflow a consuming project uses
- `.github/workflows/spec-alignment.yml`, the advisory md/yaml agent

**`fmt` reports rather than rewrites.** PHP's YAML parsers discard comments, so a
parse-and-dump formatter would delete every explanatory note an author had written.
Losing someone's reasoning to fix an ordering nit is the wrong trade, so until there is
a comment-preserving emitter the fix stays manual and the report stays precise. It
found three ordering slips in our own fixtures the first time it ran.

**Ordering applies to keys, never to members.** Trigger declaration order *is*
execution order, so sorting members would quietly change behaviour.

**The pipeline test is the one that proves the wiring.** Every layer is unit-tested in
its own package, which proves each is correct and proves nothing about them working
together. It also surfaced a real constraint: PHP loads a class once per process, so
`check` must be a one-shot command rather than something a long-lived worker calls
repeatedly.

### Step 8 — First real entity
Port one entity out of the existing WordPress plugin. That is the best test of the
schema format we have — better than inventing a `Post` example.

---

## 16. Open questions

- **Root query fields.** The GraphQL manifest registers object types, enums and
  mutations, but no entry points — a declared `queries:` block generates an injectable
  PHP finder that nothing exposes. As it stands there is no way to fetch an entity
  through the generated API. Found by porting the clog post types.
- **Cascade guard for `postCommit` mutations** — depth limit, cycle detection, or
  documented-and-your-problem?
- **Runtime model** — confirm immutable Entity snapshot + Mutator command buffer.
- **Finder vs statics** — confirm generated `PostFinder`.
- **Pagination shape** — cursor format, and whether it is opaque.

---

## 17. Deferred work

Not questions — decided, just not built.

- **A template repository.** A `create-project` starting point with `eleph.json`, a
  project spec, the directory skeleton, the CI workflow and the skills already in
  place. Every project needs all of it and none of it varies much.
- **An eval suite for the spec-authoring skill.** Wanted, but a corpus built before the
  interview has met real specs would enshrine today's guesses as the expected answers.
  See [DECISIONS-FOR-REVIEW.md](DECISIONS-FOR-REVIEW.md) §6.
