# Clog, spec'd

The three post types from [clog](https://github.com/hsimah-services/clog) — Item,
Location and Inventory — written as PheFr specs, with the generated output committed
so the two can be compared.

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

**`ClogExpiryUnit` is declared once** as `types/ExpiryUnit.yml` and appears as a PHP
backed enum, a `VARCHAR(6)` column sized to its longest member, and a GraphQL enum
whose `DAYS`/`MONTHS` names sit over the stored `days`/`months`. The hand-written
version had that mapping in three files.

**The projection is explicit.** `postId` comes from the `ClogPost` pattern, so every
entity that participates in the WordPress admin says so in one line, and the compiler
refuses that pattern on a non-WordPress driver.

## What it does not capture — three gaps this exercise found

### 1. There is no list-of-scalar field type

`barcodes` is `[String]` in the existing API. The closest PheFr offers is `json`, which
is what the spec uses, and it is a poor fit twice over: the GraphQL field degrades from
`[String]` to `String`, and a `LONGTEXT` blob cannot be indexed.

That second point matters more than it looks. Scanning a barcode to find an item is
presumably the query this data exists to serve, and as JSON it cannot be one.

Two ways out, and they are not equivalent:

- **A `list` modifier on field types.** Faithful to the current API, and the column
  stays a blob — so it fixes the type and not the lookup.
- **A `Barcode` entity** with an indexed unique `code` and an edge to `Item`. Changes
  the data model, and makes barcode lookup an actual indexed query.

The second is probably the better system and the first is the smaller change. This is
a decision about Clog, not about PheFr, which is why the spec models it as `json` and
leaves the choice open.

### 2. There is no composite value object

`defaultExpiry` is a nested `ClogDefaultExpiry` object in GraphQL, built from two meta
fields and returned as null unless both are present. PheFr flattens it to
`defaultExpiryUnit` and `defaultExpiryValue`, so the API shape changes.

Declared types alias exactly one primitive, so they cannot express a two-field
composite. Supporting one would mean embedded types that group fields into a nested
GraphQL type while storing as separate columns.

### 3. `post_title` becomes `name`, not `title`

WPGraphQL exposes `title` for free from the post row. Under PheFr the custom table is
authoritative and the post row is a projection, so the field has to be declared — and
`name` reads better for a Location than `title` does. It is a rename in the client
either way, so it may as well be the better name; but it *is* a breaking change.

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
