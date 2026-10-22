# What an integration contains

## Runtime library (PHP: `packages/<name>/`; C#: `dotnet/src/Elephentity.<Name>/`)

| Path | Holds |
|---|---|
| `composer.json` / `.csproj` | Runtime and host-library dependencies; no compiler dependency |
| `src/Manifest` | Typed data objects the builder emits |
| `src/Registration` | Turns manifest entries into host-library configuration |
| `src/Resolver` | Calls the core gateway / query interfaces, preserving policies |
| `src/SchemaBuilder.php` | Host-specific assembly, where the host needs it |
| `src/Verification` | Optional reusable `Verifier` implementation |
| `tests/` | Drive the public integration with a real gateway and the real host library |

PHP integrations join the `php-v*` family; C# packages release under `dotnet-v*`.

## Builder (`builders/codegen-<name>/`)

`rust/provides.json` (integration keys), `rust/manifest.rs` (the emitter), `rust/verify.php`
or the C# `check` command, and the files from `eleph-add-builder/reference/anatomy.md`.
Target naming: the PHP GraphQL target is `graphql-php` while the integration key is
`graphql` — they are different things and both appear in `eleph.json` and specs.

## What a spec author sees

```json
"graphql-php": { "builder": "vendor/bin/eleph-gen-graphql-php", "output": "generated/graphql" }
```

```yaml
# project.yml            # entity
integrations:            integrations:
  graphql: {}              graphql: { singular: Book, plural: Books }
```
