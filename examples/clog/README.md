# Clog, spec'd

The three post types from [clog](https://github.com/hsimah-services/clog) — Item,
Location and Inventory — written as PheFr specs, with the generated output committed
so the two can be compared.

This is a smoke test of the generator against real post types, not a migration plan.
Clog is unfinished and the point was to find out whether its types produce signed,
coherent code. They do: 22 files, all gates green. The notes below are what the
exercise turned up, kept because they are cheap to write down now and expensive to
rediscover later — not because any of them needs acting on.

All four gates pass:

```
phefr fmt        Specs are in canonical form.
phefr validate   Specs are valid: 3 entities, 1 type.
phefr generate   22 file(s).
phefr check      Conformant: 3 type(s), every field resolves.
```

## What the spec captures cleanly

**The post types become entities with real columns.** `clog_item_id` and
`clog_location_id` stop being untyped postmeta strings and become indexed
`BIGINT UNSIGNED` columns on `wp_clog_inventory`, placed there by the framework because
`Inventory.item` is declared `cardinality: one` — nobody wrote a column name.

**One barcode, one indexed column.** `barcode` is a unique `VARCHAR(64)`, so finding an
item by scanning is an index lookup rather than a scan of serialized blobs.

**`ClogExpiryUnit` is declared once** as `types/ExpiryUnit.yml` and appears as a PHP
backed enum, a `VARCHAR(6)` column sized to its longest member, and a GraphQL enum
whose `DAYS`/`MONTHS` names sit over the stored `days`/`months`. The hand-written
version had that mapping in three files.

**The projection is explicit.** `postId` comes from the `ClogPost` pattern, so every
entity that participates in the WordPress admin says so in one line, and the compiler
refuses that pattern on a non-WordPress driver.

## What it does not capture

Both serialization gaps are closed by changing the model rather than the framework:
`barcode` is now one indexed unique `VARCHAR(64)` per item, and `defaultExpiry` was
already two plain columns. Nothing is serialized except the `json` primitive, which
nothing here uses.

What remains is the WordPress surface.

### The post row stops being the entity

This started as "`post_title` becomes `name`", which is the least of it. The rename
itself is a free choice: PheFr has no opinion about the field name, only that it must
be *declared*, because the custom table is authoritative and the post row is a
projection. Call it `title` in the spec and clients see `title`.

The real change is everything else that came free with being a post type.

**What WPGraphQL gave you.** `ClogItem` is a registered post type, so WPGraphQL
supplies `title`, `databaseId`, `date`, `modified`, `slug`, `status`, `content` and
`author` without anyone asking, plus root fields `clogItem(id:)` and
`clogItems(where:)` carrying WP's filtering, ordering and cursor pagination.

Under PheFr, `Item` is built solely from the spec. You get precisely what you declared:
`id`, `createdAt`, `updatedAt`, `postId`, `name`, `barcode`, `defaultExpiryUnit`,
`defaultExpiryValue`. `createdAt` and `updatedAt` cover what `date` and `modified` did;
the rest either need declaring or need to go.

**A gap in PheFr, not in the model.** The manifest today registers object types, enums
and mutations — and no root query fields. A declared `queries:` block generates an
injectable PHP finder, but nothing exposes it to GraphQL, so there is currently no way
to *fetch* an Item through the generated API at all. Entry points are the missing piece
of the plugin layer, and this port is what surfaced it.

**The divergence hazard.** `show_ui: true` with `supports: ['title']` means a human can
edit the title in wp-admin. Under PheFr the column is authoritative and `post_title` is
written by the Mutator as part of the same unit of work — so an admin edit changes the
projection and not the truth, and nothing notices. `OrphanGuard` catches deletes;
nothing catches edits.

Three ways out, and only one of them is honest:

- **Stop supporting `title` on the post type.** The post row becomes what the design
  says it is — an anchor for the ecosystem, holding nothing. Given Clog has its own
  React client, losing the wp-admin title column costs little.
- **Sync `post_title` back on `post_updated`.** Makes the projection bidirectional,
  which contradicts "the custom table is authoritative" and invites write loops
  between the hook and the Mutator.
- **Let them diverge** until the next write re-projects. Silently wrong, which is the
  worst of the three.

Worth noting that PheFr's own `PostTypeRegistrar` currently emits `supports: ['title']`,
so it ships the same hazard. That wants changing.

### Post type registration is not in the spec

Related, and blocking for this port: `PostTypeRegistrar` hardcodes `public: true`,
`show_in_rest: false` and `supports: ['title']`. Clog needs `public: false`,
`show_ui: true`, `show_in_menu: 'clog'`, `exclude_from_search: true` and a full label
set. None of that is expressible today.

The `ClogPost` pattern is the natural home for it — it is already the thing that says
"this entity participates in the WordPress admin", and a pattern can carry storage
configuration.

## What this exercise confirmed

The "both or neither" rule on `defaultExpiry` — the resolver returns null unless unit
and value are both set — is exactly the cross-field invariant that has nowhere to live
in a per-value processor. Both fields carry `verify: true`, and the generated
`ItemDefaultExpiryUnitVerifier` receives an `ItemMutationContext` that can read the
other field's pending value. The rule stops being implicit in a resolver and becomes a
named, testable class the application cannot boot without.

## Out of scope

`snapshots.php` reads `wp_posts` and `wp_postmeta` directly to build a SQL dump. With
entities in custom tables it needs rewriting against those, which is a straightforward
change but not one the spec describes.
