# Elephentity
![Elephentity logo](./assets/elephentity.svg)

An AI-native framework for building business logic in PHP or C#. Utilizes layers of
agentic rules and skills to turn human readable specs into consistent, scalable and
reliable underlying code.

The command is `eleph`; the PHP namespace is `Eleph\`.

## Stacks

One spec language, compiled by one `eleph` binary, with a builder per target:

| Stack | Builders | Runtime and adaptors | Status |
|---|---|---|---|
| PHP and SQLite | `eleph-gen-php`, `eleph-gen-sqlite`, `eleph-gen-graphql-php` | Composer: `elephentity/runtime`, `elephentity/sqlite`, `elephentity/graphql` | Established |
| C# and Cosmos DB | `eleph-gen-csharp`, `eleph-gen-cosmos`, `eleph-gen-graphql-csharp` | NuGet: `Elephentity.Runtime`, `Elephentity.Cosmos`, `Elephentity.GraphQL` | Experimental: alpha releases |
| C# and SQLite | `eleph-gen-csharp`, `eleph-gen-sqlite`, `eleph-gen-graphql-csharp` | NuGet: `Elephentity.Runtime`, `Elephentity.Sqlite`, `Elephentity.GraphQL` | Experimental |

`eleph-gen-sqlite` and `eleph-gen-tsquid` write either stack: the target's `language` (`"php"` or `"csharp"`) says which.
`eleph-gen-tsquid` generates the route manifest a [tsquid](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen-tsquid)
client reads. [`dotnet/`](dotnet) documents the NuGet packages.

## Quick start

```bash
curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh
composer require elephentity/runtime elephentity/memory
composer require --dev elephentity/codegen elephentity/codegen-php
```

`install.sh` puts the `eleph` compiler, a single binary, in `~/.local/bin` (`install.ps1` on
Windows). It needs no PHP: the same `eleph` compiles specs for PHP and .NET projects alike.

`elephentity/memory` is the neutral driver: raw PHP objects, no platform. For a real database,
add SQLite: `composer require elephentity/sqlite` and `composer require --dev
elephentity/codegen-sqlite`. There are no WordPress or WPGraphQL adaptors: support was dropped,
and the last releases (`elephentity/wordpress` 0.4.x, `elephentity/wpgraphql` 0.3.x) are the end
of those lines.

For C#, install `eleph` the same way and add the runtime from NuGet instead of Composer:

```bash
dotnet add package Elephentity.Runtime
dotnet add package Elephentity.Runtime.Memory
```

then pin the generator and the C# builder in `eleph.json`, and let `eleph install` fetch them
(see [Install without PHP](#install-without-php)):

```json
{
    "spec": "spec",
    "codegen": "eleph-codegen@0.7.0",
    "targets": {
        "csharp": {
            "builder": "eleph-gen-csharp@0.1.0-alpha.1",
            "output": "Generated/Entities",
            "namespace": "App.Entity",
            "typeNamespace": "App.Type"
        }
    }
}
```

The specs below are the same for both stacks. Two files. The project, and one entity:

```yaml
# spec/project.yml
project: MyApp
storage:
  driver: memory
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

For PHP, name the builders in `eleph.json` — Elephentity generates nothing itself, so a
target without a `builder` is refused:

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
        "memory": {
            "builder": "vendor/bin/eleph-gen-memory",
            "output": "generated/memory"
        }
    }
}
```

```bash
eleph validate
eleph generate
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
    NoteMutationContext.php      what a verifier or sideEffect can read mid-commit
    NoteHydrator.php             row → Note
    NoteDeleter.php
    NoteVerifiers.php            dispatch to your field verifiers
    NoteSideEffects.php             dispatch to your sideEffects
```

`memory` compiles to no physical schema — there is no `generated/memory/` beyond that
directory existing, since `MemoryAdaptor` has no table to provision. Install
`elephentity/sqlite`, set `driver: sqlite` in `spec/project.yml`, and a `sqlite` target writes
`generated/sqlite/storage-manifest.php` and `install.php` alongside it (`"language": "php"` on the target; with
`"language": "csharp"` it writes `SqliteStorageManifest.cs` for `Elephentity.Sqlite` instead).

Every file carries a `sha256` digest in its header. Editing one is detected and
rejected by the next build.

[docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) continues from here: autoloading,
wiring, migrations.

## Repository layout

The compiler, the code generator, every builder, the PHP runtime and adaptors and the .NET
packages live in this repository. Each is its own directory with its own CI
and its own version:

| Directory | What it is | You install it from |
|---|---|---|
| `compiler/` | `eleph`, the spec compiler (Rust) | GitHub Releases (`install.sh`) |
| `builders/codegen` | `eleph-codegen`, which runs the builders | GitHub Releases, or Composer |
| `builders/codegen-php`, `-sqlite`, `-graphql-php`, `-tsquid` | PHP, SQLite, GraphQL and tsquid builders | Composer (sqlite and tsquid also GitHub Releases, for C#) |
| `builders/codegen-csharp`, `-cosmos`, `-graphql-csharp` | C#, Cosmos and GraphQL.NET builders | GitHub Releases, via `eleph install` |
| `packages/runtime`, `memory` | PHP runtime and the neutral memory driver | Composer |
| `packages/sqlite`, `graphql` | PHP SQLite and GraphQL adaptors | Composer |
| `dotnet/` | The .NET runtime and adaptors | NuGet |
| `patterns/` | The pattern catalogue | |

Packagist can't publish several packages from one repository, so every Composer package is
mirrored, read-only, to its own repository. Open issues and pull requests here, never on a mirror.

**Releases are per program.** A tag names what it releases: `v1.2.3` is `eleph`,
`eleph-gen-csharp-v1.2.3` is that builder, `dotnet-v1.2.3` publishes the NuGet packages, and
`php-v1.2.3` releases the PHP packages (`runtime`, `memory`, `sqlite` and `graphql`) together under
one version, like the NuGet packages. [docs/RELEASING.md](docs/RELEASING.md)
has the details. The full layout, with the conventions that apply to each, is in
[CLAUDE.md](CLAUDE.md#layout).

## Install without PHP

No PHP, Composer or cargo is needed for a project whose builders are Rust programs:

```bash
curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh   # eleph
eleph install            # fetch eleph-codegen and the builders eleph.json pins as "name@version"
eleph install --check    # fail if anything pinned is missing or changed
```

Windows uses `install.ps1`. See [Getting started](docs/GETTING_STARTED.md#install-without-php).

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
// generated/sqlite/storage-manifest.php  (with elephentity/sqlite installed)
'body' => new Column('body', 'TEXT', false, false, null),
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
'barcode' => new Column('barcode', 'TEXT', true, false, null),
'clog_item_barcode_uniq' => new Index('clog_item_barcode_uniq', ['barcode'], true),
```

and `install.php` adds `CHECK (length("barcode") <= 64)` to the column.

**A type is declared once and appears everywhere it is needed.**

```yaml
# spec/types/ExpiryUnit.yml
type: ExpiryUnit
primitive: string
values: [days, months]
```

A PHP backed enum, a `TEXT` column with a `CHECK ... IN ('days', 'months')` constraint, and —
with the `graphql` integration configured — a GraphQL enum whose `DAYS`/`MONTHS` names sit
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

`cardinality: one` puts an indexed `location_id INTEGER` on `clog_inventory`,
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
eleph fmt                 # canonical key order, so diffs stay semantic
eleph validate spec       # well-formed and semantically closed
eleph generate --check    # the tree matches the spec
eleph check               # each target's own checker, e.g. eleph-check-php
```

`validate` writes no generated files. To validate before builder executables are
available, pass saved discovery metadata with
`eleph validate spec --provides provides.json`; this also works without
`eleph.json`. See [docs/CI.md](docs/CI.md) for capturing the snapshot and its limits.

[docs/CI.md](docs/CI.md) — what each one catches that the others cannot.

## The worked example

[`clog`](https://github.com/hsimah-services/elephentity-examples/tree/main/clog), in
[elephentity-examples](https://github.com/hsimah-services/elephentity-examples), is
three real WordPress post types written as specs, with the generated tree committed so
the two can be read side by side — built and tested as a real, independent WordPress
plugin rather than a fixture inside this repository (elephentity#79).

| Path | What it shows |
|---|---|
| `spec/` | Three entities, two patterns, one type. |
| `generated/` | The 36 files they produce, across three targets. |
| `src/Bootstrap.php` | The assembly order, written down once. |
| `src/Container.php` | Thirty lines, so it depends on no particular container. |
| `src/Contract/ItemSearch.php` | A hand-written finder, and how it gets a lazy query. |
| `src/Contract/DefaultExpiryIsPaired.php` | A cross-field rule, as one class implementing both halves. |
| `clog.php` | The WordPress plugin around it, and nothing else. |

Both the wiring and the generated tree are analysed at PHPStan level max, so a
reference that stops compiling is a build failure.

[`clog`'s own README](https://github.com/hsimah-services/elephentity-examples/blob/main/clog/README.md)
covers what the port to a spec captured cleanly, and what it did not.

## Docs

- [docs/GETTING_STARTED.md](docs/GETTING_STARTED.md) — laying out a project
- [docs/GLOSSARY.md](docs/GLOSSARY.md) — what every term means, and why
- [docs/PLAN.md](docs/PLAN.md) — every design decision and the reasoning behind it
- [docs/CI.md](docs/CI.md) — the four gates
- [docs/BUILDERS.md](docs/BUILDERS.md) — writing a builder for another language
- [docs/PUBLISHING-SCHEMAS.md](docs/PUBLISHING-SCHEMAS.md) — hosting the JSON Schemas for editor validation
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — working on the framework itself
- [docs/README.md](docs/README.md) — all of the above, indexed by task

## Agent skills

Elephentity ships skills that teach an agent to use and extend it, in [skills/](skills). They are
short, and the detail sits one hop away, so an agent loads only what the task needs.
[skills/README.md](skills/README.md) is the index.

| Skill | For |
|---|---|
| `eleph` | working in a project: the spec format, the build loop (PHP and C#), finding what is left to implement |
| `eleph-spec-author` | turning a written description into a spec, and what to ask first |
| `eleph-add-builder` | writing an output generator for a new language or file type |
| `eleph-add-adaptor` | adding a storage driver |
| `eleph-add-integration` | exposing entities over a new API |

```bash
git clone --depth 1 https://github.com/hsimah-services/elephentity.git /tmp/elephentity-skills
cp -r /tmp/elephentity-skills/skills/eleph /tmp/elephentity-skills/skills/eleph-spec-author .claude/skills/
```

The three `eleph-add-*` skills are for working inside this repository. The docs have a task-first
index too: [docs/README.md](docs/README.md).
