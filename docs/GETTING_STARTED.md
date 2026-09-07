# Getting started

## Install

> Not on Packagist yet. Until it is, add this repository as a Composer `path` or `vcs`
> repository — the package names below are what it will publish as.

```bash
composer require elephentity/runtime elephentity/wordpress elephentity/wpgraphql
composer require --dev elephentity/schema elephentity/codegen elephentity/cli
```

The dev packages run at build time and never ship. The others do.

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
    "output": "generated",
    "namespace": "App\\Entity",
    "typeNamespace": "App\\Type"
}
```

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
  driver: wordpress
  tablePrefix: app_
```

Required. It holds the settings no entity can sensibly vary.

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
vendor/bin/eleph validate spec
vendor/bin/eleph generate
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
vendor/bin/eleph generate
ls generated/Note/Contract/
# NoteBodyVerifier.php
```

That interface has no implementation, so the application will not boot. Write one in
`src/`, wire it into your container, and `vendor/bin/eleph check` goes green.

That loop — change the spec, regenerate, implement what appeared under `Contract/` — is
the whole workflow.

## Wire it up

Codegen produces classes; something has to assemble them. The order matters, so the
example carries a working one rather than this page describing it:
[`examples/clog/src/Bootstrap.php`](../examples/clog/src/Bootstrap.php), with
[`examples/clog/clog.php`](../examples/clog/clog.php) as the WordPress plugin around
it. Both are analysed at PHPStan level max against the committed generated tree, so a
reference that no longer compiles is a build failure rather than a surprise.

In outline:

```php
$storage   = WordPress::adaptor($database, WordPress::manifest('generated/storage-manifest.php'));
$container = /* your PSR-11 container, holding the generated classes and your contracts */;
$catalogue = new Catalogue($container);

$runtime = new Runtime(
    $storage,
    $catalogue,
    new UnitOfWorkFactory($storage, $catalogue, $processors),
);

(new BootCheck($catalogue, $container))->run();
```

`$runtime` is the one object the application holds. It is the `EntityGateway`, so the
GraphQL layer takes it directly:

```php
Plugin::fromManifest('generated/graphql-manifest.php', $runtime, $processors)->boot();
```

## Create the tables

Migrating means diffing against a live database, which the build cannot reach — so
this is a runtime call, on plugin activation:

```php
$plan = (new SchemaInstaller($database, $manifest))->install();
```

Additive changes — a new table, a new nullable column, a new index — are applied.
Anything destructive or ambiguous is refused, and then **nothing** is applied:
`$plan->refusals` says what it saw and why it will not guess, and an explicit migration
is the answer. A half-migrated schema is worse than an unmigrated one.

## Wire up your editor

```bash
cp vendor/elephentity/elephentity/.vscode/settings.json.example .vscode/settings.json
```

Validates spec files as you type against the published schemas, and marks `generated/`
read-only so the editor refuses an edit the build would reject later. See
[PUBLISHING-SCHEMAS.md](PUBLISHING-SCHEMAS.md).

## Wire it into CI

See [CI.md](CI.md). Four gates, in order.

## Install the agent skills

Elephentity ships skills that teach an agent how to use it:

```bash
cp -r vendor/elephentity/elephentity/skills/* .claude/skills/
```

- `eleph` — the spec format, the build loop, and how to find what is left to implement
- `eleph-spec-author` — turning a written description into a spec, and what to ask first

## Committing generated code

Commit it. It is the reviewable output of the spec, `generate --check` proves it
matches, and a reviewer seeing a diff to `generated/` alongside a diff to `spec/` can
tell at a glance whether the change did what it claimed.
