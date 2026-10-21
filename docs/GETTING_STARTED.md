# Getting started

## Install

```bash
curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh
composer require elephentity/runtime elephentity/memory
composer require --dev elephentity/codegen elephentity/codegen-php
```

The first line installs `eleph`, the compiler: one binary, no PHP needed (`install.ps1` on
Windows).

**Installation is additive, by driver and by integration.** `elephentity/runtime`
carries the storage port and the unit of work.
`elephentity/memory` is the neutral driver — raw PHP objects, no platform — so a project
that installs nothing else still has somewhere to write. Want a real database instead?

```bash
composer require elephentity/sqlite
composer require --dev elephentity/codegen-sqlite
```

and drop `elephentity/memory` if you are not keeping it as a second driver. Want
GraphQL on top of that? `composer require elephentity/graphql` and
`composer require --dev elephentity/codegen-graphql-php`. Nobody who does not ask for
SQLite gets SQLite.

**The code generator is separate software**, which is why it is a second line and a dev
dependency. `elephentity/codegen` runs your builders and writes the tree;
`elephentity/codegen-php` is the builder that emits PHP. If you later want TypeScript
types from the same spec, you add a builder for it and a target in `eleph.json` — nothing
else changes, and no part of the framework learns what TypeScript is.

That gives you `eleph` on your `PATH`, and in `vendor/bin`: `eleph-codegen` and
`eleph-gen-php`, plus `eleph-gen-memory` from `elephentity/memory` and whichever of
`eleph-gen-sqlite` / `eleph-gen-graphql-php` you installed.
`vendor/bin/eleph-codegen doctor` is the quickest way to confirm they are all where the
config expects.

## Install without PHP

A project that generates C# (or anything else a Rust builder emits) needs no PHP, no Composer and
no cargo. Three steps:

```bash
# 1. The `eleph` command: Linux and macOS (use install.ps1 on Windows)
curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh

# 2. Pin the generator and each builder in eleph.json, by version (below), then fetch them
eleph install

# 3. The gates
eleph install --check && eleph validate && eleph generate --check
```

`install.sh` checks the download against the release's `SHA256SUMS` and puts `eleph` in
`~/.local/bin` (`ELEPH_INSTALL_DIR` changes that; `ELEPH_VERSION` picks a release). On Windows,
`irm https://github.com/hsimah-services/elephentity/releases/latest/download/install.ps1 | iex`
installs `eleph.exe` to `%LOCALAPPDATA%\eleph\bin`.

A pin is `name@version`, written where you would write the program:

```json
{
  "spec": "spec",
  "codegen": "eleph-codegen@0.2.0",
  "targets": {
    "csharp": { "builder": "eleph-gen-csharp@0.1.0", "output": "generated" },
    "other":  { "builder": "their-builder@3.0.0", "source": "owner/repo", "output": "web/generated" }
  }
}
```

`eleph install` downloads each pinned release from GitHub, verifies it against that release's
`SHA256SUMS`, unpacks the one binary into `tools/builders/` (the first place the generator looks)
and records what it did in `tools/builders/.eleph-install.json`. It skips whatever is already
there at the pinned version, so it is cheap to run on every build. `eleph install --check`
installs nothing and fails if a pinned program is missing, is another version, or was modified
after it was installed, which is the form for CI.

It fetches exactly what you pinned. There is no "latest", so a build never changes because
somebody published a release, and a builder you did not pin (`vendor/bin/eleph-gen-php`, a path)
is left to whatever installed it. The repositories of the Elephentity programs are known; for
anything else, say where its releases are with `"source": "owner/repo"` on the target. Releases
are built for linux-x64, linux-arm64, osx-arm64 and win-x64.

PHP projects can keep installing builders with Composer: `eleph` finds `eleph-codegen` and
the builders in `vendor/bin`. `eleph` itself always comes from `install.sh`; the PHP compiler
that `elephentity/cli` used to provide was retired after v0.14.2.

## Lay out the project

```
eleph.json
spec/
  project.yml        the storage driver and table prefix
  entities/
  patterns/
  types/
src/                 your handlers, verifiers and value classes
generated/           machine-owned, committed, never edited
```

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

Add a `sqlite` target (`vendor/bin/eleph-gen-sqlite`) and a `graphql-php` target
(`vendor/bin/eleph-gen-graphql-php`) if you installed those packages above.

Every target names the program that produces it. Elephentity generates nothing itself, so
a target without a `builder` is one nothing can produce and `eleph.json` refuses it.
`vendor/bin/eleph-gen-php` is where Composer put the one you installed above; check with
`vendor/bin/eleph-codegen doctor`.

**Configure a target for your driver, and one per integration you use.** They are what
declares those things exist: `eleph generate` asks every configured builder what it
provides *before* it reads a spec, so `driver: sqlite` with no sqlite target is an
error naming the drivers that are installed, and `integrations: { graphql: … }` with
no graphql-php target is an unknown integration. A project on the neutral `memory` driver
configures neither and installs neither.

They write **inside** the PHP tree, each in its own directory. The manifests are PHP the
runtime loads by path, so that is where they belong — but the PHP builder cannot produce
them, since compiling a storage schema needs code that knows what a table is. Each
target sweeps only its own directory, so nesting is safe.

Add both namespaces to Composer's PSR-4 autoload map:

```json
"autoload": {
    "psr-4": {
        "App\\Entity\\": "generated/",
        "App\\Type\\": "src/Type/",
        "App\\": "src/"
    }
}
```

## Declare the project

```yaml
# spec/project.yml
project: MyApp
storage:
  driver: memory
```

Required. It holds the settings no entity can sensibly vary. On `driver: sqlite`,
`storage` also takes `tablePrefix`, from `elephentity/codegen-sqlite`'s own declaration.

## Write the first entity

```yaml
# spec/entities/Note.yml
entity: Note
description: Something written down.
storage:
  table: note
fields:
  body:
    type: text
    required: true
```

```bash
eleph validate spec
eleph generate
```

You now have `generated/Note/Note.php`, `NoteMutator`, `NoteMutationContext`,
`NoteHydrator` and the bridges — all signed, all locked.

## Add something that needs you

```yaml
  body:
    type: text
    required: true
    verify: true      # ← new
```

```bash
eleph generate
ls generated/Note/Contract/
# NoteBodyVerifier.php
```

That interface has no implementation, so the application will not boot. Write one in
`src/`, wire it into your container, and `eleph check` goes green.

That loop — change the spec, regenerate, implement what appeared under `Contract/` — is
the whole workflow.

## Wire it up

Codegen produces classes; something has to assemble them: the storage adaptor, a PSR-11
container holding the generated classes and your contracts, then the runtime on top. The order
matters, and it is easy to get subtly wrong, so read a working one rather than this page:

- [hsimah/clog](https://github.com/hsimah/clog) is a real application on the PHP and SQLite stack
  (`server/standalone/`), with its generated tree committed.
- [elephentity-examples](https://github.com/hsimah-services/elephentity-examples) has minimal
  examples. Its `clog` is still a WordPress plugin, so use its `Bootstrap.php` for the shape of
  the assembly only, not for the adaptor.

What the pieces are: `elephentity/sqlite` provides `Database` (a PDO SQLite connection) and
`SQLiteAdaptor`, which implements the runtime's `StorageAdaptor`; the generated
`generated/sqlite/storage-manifest.php` describes the tables it reads and writes. The runtime's
`Runtime` is the one object the application holds. It is the `EntityGateway`, and
`elephentity/graphql` takes it directly.

## Create the tables

`generated/sqlite/install.php` returns a closure that creates the initial schema, atomically:

```php
$install = require 'generated/sqlite/install.php';
$install($database->pdo, 'app_'); // the second argument is an optional extra table prefix
```

It is an explicit initial installation: existing tables make it fail and roll back. Evolving
a populated database is an application migration you write and review, because the build cannot
see a live database. Use the same prefix when loading the manifest with
`StorageManifest::withPrefix()`.

## .NET projects

A project that generates C# needs no PHP and no Composer. The four gates are the same four, run by
the Rust `eleph`:

```bash
eleph fmt                  # specs are in canonical key order
eleph validate             # specs are well-formed and semantically closed
eleph generate --check     # the committed tree matches the specs
eleph check                # the generated tree is coherent
```

The first three read only the specs and the builders' `describe` answers. `eleph check` is the one
that differs by language, because "coherent" depends on what was generated. So `eleph` does not
check anything itself: each builder says how its tree is checked, in `describe`
(`provides.check.command`), and `eleph check` runs that command for every target. For a C# target
the checker is `ContractCheck` from `Elephentity.Runtime.Testing`, run as a test project. Only the
application knows where that project is, so it is named in the target's config in `eleph.json` and
the builder echoes it back:

```json
{ "targets": { "csharp": { "builder": "eleph-gen-csharp", "output": "generated",
                           "check": ["dotnet", "test", "tests/Contracts"] } } }
```

A checker exits zero to pass and non-zero to fail, and prints one
`{"severity": "error|warning|info", "message": "..."}` per line. `eleph check` never passes silently:
a target whose builder provides no checker is listed as a warning, and if no target at all was
checked the gate fails and names them.

## Wire up your editor

```bash
curl -fsSL https://raw.githubusercontent.com/hsimah-services/elephentity/main/.vscode/settings.json.example -o .vscode/settings.json
```

Validates spec files as you type against the published schemas, and marks `generated/`
read-only so the editor refuses an edit the build would reject later. See
[PUBLISHING-SCHEMAS.md](PUBLISHING-SCHEMAS.md).

## Wire it into CI

See [CI.md](CI.md). Four gates, in order.

## Install the agent skills

Elephentity ships skills that teach an agent how to use it:

```bash
git clone --depth 1 https://github.com/hsimah-services/elephentity.git /tmp/elephentity-skills
cp -r /tmp/elephentity-skills/skills/* .claude/skills/
```

- `eleph` — the spec format, the build loop, and how to find what is left to implement
- `eleph-spec-author` — turning a written description into a spec, and what to ask first

## Committing generated code

Commit it. It is the reviewable output of the spec, `generate --check` proves it
matches, and a reviewer seeing a diff to `generated/` alongside a diff to `spec/` can
tell at a glance whether the change did what it claimed.
