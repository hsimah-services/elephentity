# elephentity-codegen-graphql-php

Build-time generator for `elephentity/graphql`, the standalone PHP integration using
webonyx. It turns Elephentity's compiled schema into a GraphQL manifest and a
conformance verifier. It requires no WordPress or WPGraphQL runtime.

## Install

```sh
composer require --dev elephentity/codegen-graphql-php:dev-main
cargo build --release --locked --manifest-path vendor/elephentity/codegen-graphql-php/Cargo.toml
```

Alternatively, `cargo install --path . --locked` installs `eleph-gen-graphql-php`.
Composer provides a shell launcher, not an automatic Rust build. Production uses
`elephentity/graphql` and the generated PHP files; the builder stays in development.

Add the target alongside PHP entity generation in `eleph.json`:

```json
{
  "targets": {
    "graphql-php": {
      "builder": "vendor/bin/eleph-gen-graphql-php",
      "output": "generated/graphql"
    }
  }
}
```

Declare `integrations: { graphql: {} }` in the project specification, then expose
entities explicitly:

```yaml
entity: Book
storage:
  table: book
fields:
  title:
    type: string
    required: true
integrations:
  graphql:
    singular: Book
    plural: Books
```

The target is `graphql-php`; the integration key is `graphql`. The driver is
independent: this can consume an entity schema using SQLite or another storage driver.
`singular` defaults to the entity name. `plural` defaults to `<Singular>Collection`,
keeping root collection and single-record fields distinct without guessing plurals.

Publish a declared query with `integrations: { graphql: { field: searchBooks } }`
on the query. Its return entity must also be exposed. The `field` override is optional.

`describe` advertises the generic integration to the compiler. This builder speaks
protocol 1 and IR 1.2; use matching compiler/orchestrator/PHP-builder versions.

## Generated artifacts

| File | Purpose |
| --- | --- |
| `graphql-manifest.php` | `Eleph\GraphQL\Manifest\Manifest`, covering object/enum types, roots, relationships, mutations, actions and published queries |
| `verify.php` | A core `Verifier` that checks manifest accessors against generated entity classes |

Keep the GraphQL output under the PHP output directory, such as
`generated/graphql`, so `eleph check` discovers its `verify.php`.
The verifier reads the manifest on disk and checks the actual generated classes;
it does not depend on a WPGraphQL verifier class.

The orchestrator signs and writes the returned PHP bodies. The generator does not
write files or build a request-time GraphQL server. At runtime, load the manifest,
pass it and an `EntityGateway` to `TypeRegistrar` and `MutationRegistrar`, and
assemble their configs with `SchemaBuilder`. The application supplies Node/PageInfo
registration, HTTP transport, authentication, and any connection argument adaptation.
`tests/runtime.php` contains an executable schema assembly example.

## Supported surface

- Scalar, declared and inline enum fields, datetime/JSON encoding and processors.
- Global IDs, entity root queries, collections, forward and inverse relationships.
- Create/update/action inputs, with managed fields excluded and immutable updates omitted.
- Declared query fields and their arguments.
- Rejection of invalid or colliding GraphQL type/field/enum names and unexposed query return types.

Delete mutations are derived from root entries by the runtime registrar. Project-wide
`rootQueries` and `mutations` toggles are intentionally not declared by this initial
integration: the current generic runtime uses roots for delete registration too.
The existing standalone runtime's ID validation, backward-pagination and query-only
schema limitations are not changed by adding this generator. Those are tracked in
[elephentity#90](https://github.com/hsimah-services/elephentity/issues/90).

## Verify

```sh
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
composer --working-dir=tests install --no-interaction --no-plugins --no-scripts
php tests/runtime.php
```

The Rust tests invoke the actual binary and check the protocol, errors and frozen
output. PHP tests install the tagged standalone runtime, validate generated schemas
with webonyx and execute queries, enums, Relay pagination, mutations and actions.
They also check the verifier against missing and matching entity accessors.

For a complete compiler-to-runtime example, see the sibling SQLite builder's
`examples/standalone` and `tools/test-pipeline.php`.
See [SOURCE.md](SOURCE.md) for provenance of the adapted generator code.
