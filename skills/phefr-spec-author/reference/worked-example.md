# A description, and the spec it produces

The reasoning matters more than the output. Most of the decisions below are not in the
description — they came from the interview, and that is the point.

## The description

> **Inventory**
>
> We track individual instances of stock. Each inventory entry is one physical thing:
> the item it is an instance of, where it is kept, when it arrived, and when it expires
> if it does at all. Items know their own default expiry so we can suggest a date when
> someone adds stock. Locations are just names. We scan barcodes to find items.

## What the description does not say

Everything the spec actually needs:

- Can two items share a barcode? (**no** — so `unique`)
- Is a barcode required? (**no** — stock arrives before labelling)
- Can an inventory entry exist without a location? (**no**)
- Does an item have one barcode or several? (**one**, after discussion — see below)
- Is `dateAdded` editable after the fact? (**yes** — corrections happen)
- Do these need to appear in wp-admin? (**yes** — there is an existing admin list)
- What happens to inventory when its item is deleted? (**deferred**)

Six of those seven change the spec. None were in the prose.

## The interesting decision

"We scan barcodes to find items" is the load-bearing sentence, and it is easy to miss.

The first draft modelled barcodes as a list, which PheFr can only store as `json` — and
a JSON column cannot be indexed. The description's one clue about *how the data is
used* rules out the obvious model. Options:

- `json`, and accept that the primary lookup is a table scan
- one barcode per item — an indexed `VARCHAR`, and lookup is an index hit
- a `Barcode` child entity — several per item, still indexed

The second was chosen, because the extra barcodes were hypothetical and the lookup was
not. **This is exactly the kind of thing to raise rather than absorb**: it is a change
to the domain model, and the author is the only one who can approve it.

## The spec

```yaml
# spec/types/ExpiryUnit.yml
type: ExpiryUnit
description: The unit a default expiry duration is counted in.
primitive: string
values:
  - days
  - months
```

```yaml
# spec/patterns/ClogPost.yml
pattern: ClogPost
description: Participation in the WordPress admin, which every type here needs.
requires:
  driver: wordpress
use:
  - Timestamps
fields:
  postId:
    type: int
    description: The wp_posts row this entity projects to.
    unique: true
    indexed: true
```

```yaml
# spec/entities/Item.yml
entity: Item
description: A thing that can be stocked, identified by its barcode.
use:
  - ClogPost
storage:
  driver: wordpress
  table: clog_item
  handle: clog_item
fields:
  name:
    type: string
    required: true
    indexed: true
    maxLength: 200
  barcode:
    type: string
    description: One per item, so that scanning is an index lookup.
    nullable: true
    unique: true
    maxLength: 64
  defaultExpiryUnit:
    type: enum
    description: Half of the default expiry; meaningless without the other half.
    nullable: true
    values: ExpiryUnit
    verify: true
  defaultExpiryValue:
    type: int
    nullable: true
    verify: true
```

```yaml
# spec/entities/Inventory.yml
entity: Inventory
description: One stocked instance of an item, in a location, with its own expiry.
use:
  - ClogPost
storage:
  driver: wordpress
  table: clog_inventory
  handle: clog_inventory
fields:
  dateAdded:
    type: datetime
    required: true
  dateExpiry:
    type: datetime
    description: Absent means it does not expire.
    nullable: true
edges:
  item:
    to: Item
    cardinality: one
    inverse:
      name: inventoryEntries
      unique: false
  location:
    to: Location
    cardinality: one
    inverse:
      name: inventoryEntries
      unique: false
```

## Why the edges look like that

An inventory entry has one item; an item has many inventory entries. So on the
`Inventory` side: `cardinality: one`, and the reverse is not unique — hence
`inverse: { name: inventoryEntries, unique: false }`. That is many-to-one, so the key
lands on `clog_inventory` as `item_id`, indexed.

The inverse **must** be named. The reverse is to-many, the derived name would be
singular, and the generator does not pluralise.

Nobody wrote `item_id`. It follows from the relation, which is the point.

## The two verifiers

`defaultExpiryUnit` and `defaultExpiryValue` both carry `verify: true` because the rule
is "both or neither" — a rule spanning two fields, which has nowhere to live in a
per-value processor. The generated `ItemDefaultExpiryUnitVerifier` receives an
`ItemMutationContext` and can read `pendingDefaultExpiryValue()`.

The rule was implicit in a GraphQL resolver before. Now it is a named class the
application cannot boot without.

## What to report back

> **Assumptions made**
> - Barcode is unique and nullable — stock can arrive unlabelled.
> - One barcode per item, not several. The description implies barcode lookup is the
>   primary query, and a JSON list cannot be indexed. **Please confirm** — this is a
>   change to the model, not just to the spec.
> - `dateAdded` is mutable, on the assumption corrections happen.
>
> **Not expressible**
> - Nothing here needed a list of scalars or a composite value object, given the above.
>
> **Deferred**
> - Deletion semantics. `onDelete` defaults to `restrict`, so deleting an Item with
>   inventory entries will fail until this is decided.
