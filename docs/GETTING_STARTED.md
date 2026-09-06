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
