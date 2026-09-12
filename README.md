# Elephentity
![Elephentity logo](./assets/elephentity.svg)

An AI-native framework for building PHP business logic. Utilizes layers of agentic
rules and skills to turn human readable specs into consistent, scalable and reliable
underlying code.

The command is `eleph`; the PHP namespace is `Eleph\`.

## Quick start

```bash
composer require elephentity/elephentity
composer require --dev elephentity/codegen elephentity/codegen-php
```

Two files. The project, and one entity:

```yaml
# spec/project.yml
project: MyApp
storage:
  driver: wordpress
  tablePrefix: app_
```

```yaml
# spec/entities/Note.yml
entity: Note
description: Something written down.
storage:
  table: note
fields:
  body:
    type: text
    description: The text of the note.
    required: true
```

Name the builders in `eleph.json` — Elephentity generates nothing itself, so a target
without a `builder` is refused:

```json
{
    "spec": "spec",
    "targets": {
        "php": {
            "builder": "vendor/bin/eleph-gen-php",
            "output": "generated",
            "namespace": "App\\Entity",
            "typeNamespace": "App\\Type"
        },
        "wordpress": {
            "builder": "vendor/bin/eleph-gen-wordpress",
            "output": "generated/wordpress"
        }
    }
}
```

```bash
vendor/bin/eleph validate
vendor/bin/eleph generate
```

```
generated/
  Catalogue.php                  entity name → its generated classes
  Wiring.php                     what the container must provide
  class-map.php
  Note/
    Note.php                     immutable, constructor-private, getters only
    NoteMutator.php              setters, one per writable field
    NoteInput.php                raw array → typed pending changes
    NoteMutationContext.php      what a verifier or trigger can read mid-commit
    NoteHydrator.php             row → Note
    NoteDeleter.php
    NoteVerifiers.php            dispatch to your field verifiers
    NoteTriggers.php             dispatch to your triggers
  wordpress/
    storage-manifest.php         the physical schema
    post-types.php
    taxonomies.php
```

Every file carries a `sha256` digest in its header. Editing one is detected and
rejected by the next build.

[docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) continues from here: autoloading,
wiring, migrations.

## Spec to artifact

**A field becomes a typed property and a column.** `body: { type: text }` above:

```php
// generated/Note/Note.php
public function getBody(): string
{
    return $this->body;
}
```

```php
// generated/wordpress/storage-manifest.php
'body' => new Column('body', 'LONGTEXT', false, false, null),
```

**Constraints reach the schema.** Nothing names a column or an index by hand:

```yaml
  barcode:
    type: string
    nullable: true
    unique: true
    maxLength: 64
```

```php
'barcode' => new Column('barcode', 'VARCHAR(64)', true, false, null),
'clog_item_barcode_uniq' => new Index('clog_item_barcode_uniq', ['barcode'], true),
```

**A type is declared once and appears everywhere it is needed.**

```yaml
# spec/types/ExpiryUnit.yml
type: ExpiryUnit
primitive: string
values: [days, months]
```

A PHP backed enum, a `VARCHAR(6)` column sized to its longest member, and — with the
`wpgraphql` integration configured — a GraphQL enum whose `DAYS`/`MONTHS` names sit
over the stored values:

```php
enum ExpiryUnit: string
{
    case Days = 'days';
    case Months = 'months';
}
```

**An edge places its own key.** Nothing declares where it goes:

```yaml
# spec/entities/Inventory.yml
edges:
  location:
    to: Location
    cardinality: one
    inverse:
      name: inventoryEntries
      unique: false
```

`cardinality: one` puts an indexed `location_id BIGINT UNSIGNED` on `clog_inventory`,
and the inverse appears on the other side as a lazy query:

```php
// generated/Location/Location.php
/** @return EntityQuery<Inventory> */
public function inventoryEntries(): EntityQuery
{
    return $this->edges->inverseToMany('Inventory', 'location', $this->id);
}
```

**Asking for a rule generates the interface, not the rule.** Add `verify: true`:

```yaml
  body:
    type: text
    required: true
    verify: true
```

```php
// generated/Note/Contract/NoteBodyVerifier.php
interface NoteBodyVerifier
{
    public function verify(string $value, NoteMutationContext $context): Verification;
}
```

Nothing implements it, so the application will not boot until you write one in `src/`
and wire it up. That loop — change the spec, regenerate, implement what appeared under
`Contract/` — is the whole workflow.

## The four gates

```bash
vendor/bin/eleph fmt                 # canonical key order, so diffs stay semantic
vendor/bin/eleph validate spec       # well-formed and semantically closed
vendor/bin/eleph generate --check    # the tree matches the spec
vendor/bin/eleph check               # every exposed GraphQL field resolves
```

[docs/CI.md](docs/CI.md) — what each one catches that the others cannot.

## The worked example

[examples/clog](examples/clog) is three real WordPress post types written as specs,
with the generated tree committed so the two can be read side by side.

| Path | What it shows |
|---|---|
| [`spec/`](examples/clog/spec) | Three entities, two patterns, one type. |
| [`generated/`](examples/clog/generated) | The 36 files they produce, across three targets. |
| [`src/Bootstrap.php`](examples/clog/src/Bootstrap.php) | The assembly order, written down once. |
| [`src/Container.php`](examples/clog/src/Container.php) | Thirty lines, so it depends on no particular container. |
| [`src/Contract/ItemSearch.php`](examples/clog/src/Contract/ItemSearch.php) | A hand-written finder, and how it gets a lazy query. |
| [`src/Contract/DefaultExpiryIsPaired.php`](examples/clog/src/Contract/DefaultExpiryIsPaired.php) | A cross-field rule, as one class implementing both halves. |
| [`clog.php`](examples/clog/clog.php) | The WordPress plugin around it, and nothing else. |

Both the wiring and the generated tree are analysed at PHPStan level max, so a
reference that stops compiling is a build failure.

[examples/clog/README.md](examples/clog/README.md) covers what the port to a spec
captured cleanly, and what it did not.

## Docs

- [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) — laying out a project
- [docs/GLOSSARY.md](docs/GLOSSARY.md) — what every term means, and why
- [docs/PLAN.md](docs/PLAN.md) — every design decision and the reasoning behind it
- [docs/CI.md](docs/CI.md) — the four gates
- [docs/BUILDERS.md](docs/BUILDERS.md) — writing a builder for another language
- [docs/PUBLISHING-SCHEMAS.md](docs/PUBLISHING-SCHEMAS.md) — hosting the JSON Schemas for editor validation
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — working on the framework itself

## Agent skills

Elephentity ships skills that teach an agent to use it, in [skills/](skills):

- `eleph` — the spec format, the build loop, finding what is left to implement
- `eleph-spec-author` — turning a written description into a spec, and what to ask first

```bash
cp -r vendor/elephentity/elephentity/skills/* .claude/skills/
```
