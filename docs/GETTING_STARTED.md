# Getting started

## Install

> Not on Packagist yet. Until it is, add this repository as a Composer `path` or `vcs`
> repository — the package names below are what it will publish as.

```bash
composer require phefr/runtime phefr/wordpress phefr/wpgraphql
composer require --dev phefr/schema phefr/codegen phefr/cli
```

The dev packages run at build time and never ship. The others do.

## Lay out the project

```
phefr.json
spec/
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

## Write the first entity

```yaml
# spec/entities/Note.yml
entity: Note
description: Something written down.
storage:
  driver: wordpress
  table: app_note
fields:
  body:
    type: text
    required: true
```

```bash
vendor/bin/phefr validate spec
vendor/bin/phefr generate
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
vendor/bin/phefr generate
ls generated/Note/Contract/
# NoteBodyVerifier.php
```

That interface has no implementation, so the application will not boot. Write one in
`src/`, wire it into your container, and `vendor/bin/phefr check` goes green.

That loop — change the spec, regenerate, implement what appeared under `Contract/` — is
the whole workflow.

## Wire it into CI

See [CI.md](CI.md). Four gates, in order.

## Install the agent skills

PheFr ships skills that teach an agent how to use it:

```bash
cp -r vendor/phefr/phentity-framework/skills/* .claude/skills/
```

- `phefr` — the spec format, the build loop, and how to find what is left to implement
- `phefr-spec-author` — turning a written description into a spec, and what to ask first

## Committing generated code

Commit it. It is the reviewable output of the spec, `generate --check` proves it
matches, and a reviewer seeing a diff to `generated/` alongside a diff to `spec/` can
tell at a glance whether the change did what it claimed.
