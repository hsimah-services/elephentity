# Publishing the JSON Schemas

The spec schemas identify themselves by URL, so that editors can fetch them and
validate a spec file **as you type** — unknown keys, bad enum values, wrong types —
before `eleph validate` ever runs.

## What to host

Five files, copied verbatim from `packages/schema/resources/`, at exactly these URLs:

| File | URL |
|---|---|
| `common.schema.json` | `https://dev.hbla.ke/elephentity/v0/common.schema.json` |
| `project.schema.json` | `https://dev.hbla.ke/elephentity/v0/project.schema.json` |
| `entity.schema.json` | `https://dev.hbla.ke/elephentity/v0/entity.schema.json` |
| `pattern.schema.json` | `https://dev.hbla.ke/elephentity/v0/pattern.schema.json` |
| `type.schema.json` | `https://dev.hbla.ke/elephentity/v0/type.schema.json` |

The paths must match exactly. Each file's `$id` declares its own URL and its `$ref`s
name the others absolutely, so an editor that fetches `entity.schema.json` will go on
to fetch `common.schema.json` from the URL written inside it.

**Serve them as `application/json`**, over HTTPS, with `Access-Control-Allow-Origin: *`.
The CORS header is belt and braces — VS Code's YAML extension fetches from the
extension host rather than a browser — but a browser-based editor would need it.

Copying them is a two-line CI step; nothing generates or transforms them.

## The version segment

`v0` says the format is still moving. Once a URL is published, changing what it serves
changes validation for everyone pointing at it — so when the format settles, publish
`v1` alongside rather than editing `v0` in place.

## The CLI never fetches any of this

`SchemaValidator` registers each file from disk against its `$id`:

```php
$resolver->registerFile(
    'https://dev.hbla.ke/elephentity/v0/common.schema.json',
    __DIR__ . '/../../resources/common.schema.json',
);
```

The URL is a lookup key in an in-memory registry. `eleph validate` works offline, in a
sealed CI container, with the host unreachable — verified by pointing the hostname at
`127.0.0.1` and running the gate, which passes.

So hosting is **purely for editor support**. If the site goes down, nothing in the
build notices.

## Wiring an editor

Per file, with a comment the YAML language server reads:

```yaml
# yaml-language-server: $schema=https://dev.hbla.ke/elephentity/v0/entity.schema.json
entity: Item
```

Or once per project, by glob — `.vscode/settings.json`:

```json
{
    "yaml.schemas": {
        "https://dev.hbla.ke/elephentity/v0/project.schema.json": "spec/project.yml",
        "https://dev.hbla.ke/elephentity/v0/entity.schema.json": "spec/entities/*.yml",
        "https://dev.hbla.ke/elephentity/v0/pattern.schema.json": "spec/patterns/*.yml",
        "https://dev.hbla.ke/elephentity/v0/type.schema.json": "spec/types/*.yml"
    }
}
```

The glob form is better: no URL repeated in every spec file, and nothing to forget when
adding one. JetBrains IDEs have the same mapping under **Languages & Frameworks → Schemas
and DTDs → JSON Schema Mappings**.

## What this does not replace

Editor validation checks **shape**: is this a key that exists, is this value the right
type. It cannot check that an edge target resolves, that a pattern's `requires` is
satisfied, or that an action writes only what the entity declares — that is
`eleph validate`, and it stays the gate.
