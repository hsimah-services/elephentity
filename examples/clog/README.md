# Clog, spec'd

The three post types from [clog](https://github.com/hsimah-services/clog) — Item,
Location and Inventory — written as Elephentity specs, with the generated output committed
so the two can be compared.

It is also the reference wiring. `src/` holds everything between "the code is
generated" and "the application runs" — a container, the three contracts the spec says
you owe it, and `Bootstrap.php`, which is the assembly order written down once. The
plugin around it is `clog.php`.

Both are analysed at PHPStan level max against the committed `generated/` tree, along
with the generated tree itself. A reference that is not checked against the code it
wires is a snippet that rots, and the framework's central claim is that generated code
is provably typed — which is worth proving on real output rather than only on fixtures.

All four gates pass:

```
eleph fmt        Specs are in canonical form.
eleph validate   Specs are valid: 3 entities, 1 type.
eleph generate   36 file(s).
eleph check      Conformant: 3 type(s), every field resolves.
```

## Reading it

| File | What it shows |
|---|---|
| `clog.php` | The three WordPress hooks and nothing else: activation migrates, `plugins_loaded` boots. |
| `src/Bootstrap.php` | The assembly order, and why each step comes where it does. |
| `src/Container.php` | Thirty lines, so the example depends on no particular container. |
| `src/Contract/ItemSearch.php` | A hand-written finder, and how it gets a lazy query. |
| `src/Contract/DefaultExpiryIsPaired.php` | A cross-field rule, as one class implementing both halves. |

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
itself is a free choice: Elephentity has no opinion about the field name, only that it must
be *declared*, because the custom table is authoritative and the post row is a
projection. Call it `title` in the spec and clients see `title`.

The real change is everything else that came free with being a post type.

**What WPGraphQL gave you.** `ClogItem` is a registered post type, so WPGraphQL
supplies `title`, `databaseId`, `date`, `modified`, `slug`, `status`, `content` and
`author` without anyone asking, plus root fields `clogItem(id:)` and
`clogItems(where:)` carrying WP's filtering, ordering and cursor pagination.

Under Elephentity, `Item` is built solely from the spec. You get precisely what you declared:
`id`, `createdAt`, `updatedAt`, `postId`, `name`, `barcode`, `defaultExpiryUnit`,
`defaultExpiryValue`. `createdAt` and `updatedAt` cover what `date` and `modified` did;
the rest either need declaring or need to go.

**A gap this port surfaced, since closed.** The manifest registered object types, enums
and mutations and no entry points, so nothing could fetch an Item at all. Root fields
now come from the `wpgraphql` integration, and the type names match the existing API
exactly — `ClogItem` / `ClogItems`, and `ClogInventory` / `ClogInventoryEntries`,
supplied rather than derived because nothing here pluralises on your behalf.

**Nothing writes the post row, and that is now said out loud.** `postId` is a nullable
column like any other; registering the post type does not create a `wp_posts` row, and
neither does a commit. An application that wants the projection writes it in a
`postCommit` trigger, which is where a WordPress-shaped side effect of a commit
belongs — the unit of work has no business knowing what a post is. Delete events fire
for cascaded rows too, so such a trigger can keep up with a cascade rather than
leaving orphans behind it.

The divergence hazard is unchanged and worth restating. `show_ui: true` with
`supports: ['title']` would let a human edit the title in wp-admin, where the custom
table is authoritative — an admin edit changes the copy and nothing notices. Three ways
out, and only one of them is honest:

- **Stop supporting `title` on the post type.** The post row becomes what the design
  says it is — an anchor for the ecosystem, holding nothing. Given Clog has its own
  React client, losing the wp-admin title column costs little. This is now the default:
  `supports` is empty unless an entity asks for something.
- **Sync `post_title` back on `post_updated`.** Makes the projection bidirectional,
  which contradicts "the custom table is authoritative" and invites write loops.
- **Let them diverge** until the next write re-projects. Silently wrong, which is the
  worst of the three.

### Post type registration, since resolved

This was blocking for the port: `PostTypeRegistrar` hardcoded `public: true`,
`show_in_rest: false` and `supports: ['title']`, where Clog needs `public: false`,
`show_ui: true`, `show_in_menu: 'clog'`, `exclude_from_search: true` and a full label
set.

The `ClogPost` pattern is the home for it, and now is: registration arguments come from
pattern configuration, which the entity supplies with `configure:` and the compiler
validates against the pattern's own declaration.

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
