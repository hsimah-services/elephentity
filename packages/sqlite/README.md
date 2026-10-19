# elephentity-sqlite

Experimental SQLite storage for Elephentity's PHP runtime, extracted from Clog's
standalone backend. It uses PDO and implements `StorageAdaptor`; generated PHP
entities keep using `elephentity/runtime`. WordPress is not a dependency.

This package is published as an experimental alpha. Clog is still developing the
implementation. [Source provenance](docs/clog-source.json) records exact file hashes
and the Clog commit against which they were verified (`fb57b3c`).
Original source licensing is preserved in [CLOG-LICENSE](CLOG-LICENSE).

## Package boundary

- `Database`: PDO SQLite connection, parameter binding, transactions and savepoints.
- `SQLiteAdaptor`: reads, queries, counts, writes and relationship storage.
- `Manifest/StorageManifest`: tables, columns, indexes and edge placements.
- `Sql`: query compilation and schema metadata.

PHP 8.3+, PDO SQLite, mbstring and `elephentity/runtime ^0.10 || ^0.11 || ^0.12` are
required; use runtime 0.11.1 or later (see [compatibility](docs/runtime-compatibility.md)).
The Composer package name is `elephentity/sqlite`. Runtime 0.12 support is prepared
for `0.1.0-alpha.3`; `0.1.0-alpha.1` requires runtime 0.10.

The generic GraphQL PHP integration is a separate sibling repository,
`elephentity-graphql-php`, with Composer name `elephentity/graphql`.
Both packages consume the PHP runtime. SQLite is a storage choice alongside the
existing WordPress adapter; it does not replace the PHP entity generator.

## Validation and following Clog

With both sibling repositories checked out and Clog's PHP image and client build
available:

```sh
python3 tools/clog-status.py ~/Projects/clog
python3 tools/test-clog.py ~/Projects/clog
```

The status command reads both source packages and reports added, changed or deleted
files against their recorded imports. Exit 0 means unchanged, 1 means changes and 2
means invalid input. It never imports changes automatically. Review a changed file,
copy the accepted version, update its source hash, then rerun the tests.

The test command copies Clog's standalone fixture and generated entities to a
temporary directory. It resolves and installs fresh Composer dependencies for
runtime **0.11.1 and 0.12.0**, using the two local integration checkouts, then runs
adapter conformance, schema-upgrade, integration and HTTP suites for each version.
It checks the resolved versions in the lockfile. Clog's checkout is never modified.
Tests require Podman, network access for Composer and the
`localhost/clog-php:8.3-rust` image (`--image` overrides it).

Use `--runtime 0.12.0` to check one runtime. After publishing both integration
packages, verify the published artifacts with:

```sh
python3 tools/test-clog.py ~/Projects/clog --package-version 0.1.0-alpha.3
```

See [runtime compatibility validation](docs/runtime-compatibility.md) for the
release evidence and fixture revision.

Session monitoring is performed by the active agent. No background service or
scheduled job is installed by this repository.

See [the extraction notes](docs/extraction.md) before using this prototype.

## Package regression checks

After installing Composer dependencies, run `composer test` with PHP 8.3 or later.
The regression script can also use an existing dependency autoloader:

```sh
php tests/regressions.php /path/to/vendor/autoload.php
```

Paged queries cap their effective size at 1,000 records, excluding the probe row.
A zero size returns an empty terminal page; negative sizes are rejected. Targeted
to-one unlinking clears the relationship only if its current target matches.
