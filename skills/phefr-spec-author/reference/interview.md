# What to ask before writing a spec

Ordered by how much the answer changes. The first six block writing a valid spec at
all. The rest have defensible defaults, so ask them, but proceed if you must — stating
what you assumed.

Ask them in one batch. A spec is a small thing to write and an expensive thing to get
wrong, and nobody enjoys twelve consecutive questions.

---

## Blocking

### 1. Where does this live?

> Which storage driver, and what should the table be called?

Only `wordpress` exists today, so the real question is the table name. Snake case,
unprefixed — the adaptor adds the WordPress prefix.

### 2. Does it need to be a WordPress post type?

> Does anything outside this framework need to see these as posts — the wp-admin list,
> a plugin, an existing query, permalinks?

If yes, it needs a `handle` (a post type slug: lowercase, ≤20 characters) and a pattern
carrying `postId`. If no, leave `handle` out entirely and it is a plain table.

This is not a small question. A post type means the row exists in two places, and the
custom table is authoritative while the post row is a projection — so anything editing
the post directly is editing a copy.

### 3. What makes two of these the same thing?

> If I showed you two rows, what would tell you they were duplicates?

That answer is your `unique:` fields. Absent one, the entity has no natural identity
and every row is distinct — which is fine for a log or an event, and a warning sign for
anything else.

### 4. For each field: must it be supplied, and can it be empty?

> Can you create one of these without X? And once created, can X be blank?

These are different questions and the spec keeps them apart — `required` is about
creating a row, `nullable` is about what the column holds. All four combinations are
meaningful. This is the single most common thing to get wrong, because prose never
distinguishes them.

### 5. What is set once and never changed?

> Which of these are decided at creation and wrong to edit afterwards?

Those get `immutable: true`, and the generated mutator has no setter for them. Enforced
by the method not existing, which nothing can forget.

### 6. For each relationship: which side, and how many?

> Can an X have more than one Y? Can a Y belong to more than one X?

Both answers together decide where the key lives:

- one Y per X, one X per Y → one-to-one
- one Y per X, many X per Y → many-to-one
- many Y per X, one X per Y → one-to-many
- many both ways → many-to-many, join table

Declare the edge on whichever side reads naturally, then set `cardinality` and
`inverse.unique` to match. Ask about the reverse accessor name too if the reverse is
to-many, because the generator will not invent a plural.

---

## Shapes the model

### 7. What do you search or filter by?

Those fields get `indexed: true`. Indexes are cheap to add now and awkward to reason
about later, but do not index everything — each one costs write time.

### 8. Are there rules involving more than one field?

> Is there anything that has to be true across fields — a date after another date, a
> discount not exceeding a price, two fields that must both be set or both empty?

Each becomes `verify: true` on one of the fields involved. The verifier receives the
whole pending state, which is the only place such a rule can live: a per-value
processor sees one value and cannot know about the other.

### 9. What can you *do* to one of these, beyond editing fields?

> Publish it? Approve it? Transfer it? Reconcile it?

Each is an action. For each, ask **what it is allowed to change** — that becomes
`writes:`, and the handler can touch nothing else. If the answer is "anything", it is
not an action, it is a set of setters.

### 10. What has to happen when one is saved?

> Anything else that must run — an audit record, a search index, a notification, a
> cache?

Each is a trigger, and the phase matters:

- Must it be undone if the save fails? → `preCommit`
- Is it slow, or does it call something outside the database? → `postCommit`
- Does it write data of its own? → `postCommit` (`preCommit` may not mutate)

### 11. Are any of these values a thing with behaviour?

> Is "price" just an integer, or is it money — something that rounds, formats, and
> refuses to be added to a different currency?

A value type with `processors: true` means you write the class and two processors.
Worth it when the behaviour is real; overkill for a number that is only ever displayed.

### 12. How wide are the strings?

Defaults to 255. Ask when something is obviously shorter (a code, a slug) or longer (a
description). Anything indexed must stay under 768 characters.

---

## Ask last, and expect "not yet"

### 13. What happens when one is deleted?

Deletion is deliberately underbuilt: `onDelete` defaults to `restrict`, enforcement is
in the framework rather than in foreign keys, and the semantics are due to be revisited.
Ask, record the answer in the markdown, and do not build around it.

---

## What not to ask

The framework decides these, and asking invites an answer it will ignore:

- the primary key (every entity has an implicit `id`)
- column names (derived from field names)
- where a relationship's key or join table lives (derived from the relation)
- index names, table structure, DDL
