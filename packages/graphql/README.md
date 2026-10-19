# elephentity-graphql-php

Experimental framework-independent GraphQL integration for Elephentity's PHP
runtime, extracted from Clog's standalone backend. It builds a `webonyx/graphql-php`
schema without WordPress or WPGraphQL.

The Composer package name is `elephentity/graphql`, preserving Clog's existing
namespace and package layout. It requires PHP 8.3+, `elephentity/runtime ^0.10 || ^0.11 || ^0.12` and
`webonyx/graphql-php ^15.0`; use runtime 0.11.1 or later (see
[compatibility](docs/runtime-compatibility.md)). Runtime 0.12 support is prepared for
`0.1.0-alpha.3`; `0.1.0-alpha.1` requires runtime 0.10.

## Components

- `SchemaBuilder`: objects, inputs, interfaces, enums, connections and mutations.
- `Manifest`: the compiled entity, query, field and mutation descriptions.
- `Registration`: maps those descriptions to resolver configurations.
- `Relay` and `Resolver`: global IDs and connection results.

Storage remains behind the Elephentity runtime, so GraphQL does not depend on the
SQLite package. The same generated PHP entities can be exposed through this package
with an appropriate runtime and storage adapter.

## Status and validation

This prototype follows Clog's `feature/standalone-sqlite` work.
[Provenance](docs/clog-source.json) identifies the source by file hashes, now verified
against Clog commit `fb57b3c`.
The inherited source license is preserved in [CLOG-LICENSE](CLOG-LICENSE).

Clog's adapter conformance, schema-upgrade, integration and HTTP suites pass with
fresh Composer dependencies on runtime 0.11.1 and 0.12.0. With sibling
`elephentity-sqlite` and Clog checkouts, rerun the matrix using:

```sh
python3 ../elephentity-sqlite/tools/test-clog.py ~/Projects/clog
python3 ../elephentity-sqlite/tools/clog-status.py ~/Projects/clog
```

The runner requires Podman, network access for Composer, Clog's PHP image and a
built client. Add `--package-version 0.1.0-alpha.3` after publishing both integration
packages to validate the published artifacts. See
[runtime compatibility validation](docs/runtime-compatibility.md) for release evidence.

See [extraction notes](docs/extraction.md) for behavior still implemented in Clog
and the work required before a general release.

## Package regression checks

After installing Composer dependencies, run `composer test` with PHP 8.3 or later.
The regression script can also use an existing dependency autoloader:

```sh
php tests/regressions.php /path/to/vendor/autoload.php
```

Global IDs are checked against the expected public GraphQL type on root lookups,
mutations and relationship inputs. Raw row IDs remain accepted. Connections support
`first` and `after`; `last` and `before` are rejected explicitly. `first: 0` returns
an empty page and negative sizes are rejected. Query-only schemas omit the mutation root.
