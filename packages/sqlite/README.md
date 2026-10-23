> **This is the source of truth.** `hsimah-services/elephentity-sqlite` is a read-only mirror of [`packages/sqlite/`](https://github.com/hsimah-services/elephentity/tree/main/packages/sqlite) in [`hsimah-services/elephentity`](https://github.com/hsimah-services/elephentity), kept for Composer and Packagist. Open issues and pull requests on `elephentity`.

# elephentity-sqlite

SQLite storage for Elephentity's PHP runtime, extracted from Clog's standalone backend.
It uses PDO and implements `StorageAdaptor`; generated PHP entities keep using
`elephentity/runtime`. WordPress is not a dependency.

The Composer package name is `elephentity/sqlite`. It requires PHP 8.3+, PDO SQLite,
mbstring and `elephentity/runtime ^0.17`.

## Package boundary

- `Database`: PDO SQLite connection, parameter binding, transactions and savepoints.
- `SQLiteAdaptor`: reads, queries, counts, writes and relationship storage.
- `Manifest/StorageManifest`: tables, columns, indexes and edge placements.
- `Sql`: query compilation and schema metadata.

The generic GraphQL integration is a sibling package, `elephentity/graphql`. Both
consume the PHP runtime; SQLite is a storage choice alongside the WordPress adaptor and
does not replace the PHP entity generator. `codegen-sqlite` generates the storage
manifest and initial installer this package loads.

## Tests

After installing Composer dependencies, run `composer test` with PHP 8.3 or later. The
regression script can also use an existing dependency autoloader:

```sh
php tests/regressions.php /path/to/vendor/autoload.php
```

Paged queries cap their effective size at 1,000 records, excluding the probe row. A zero
size returns an empty terminal page; negative sizes are rejected. Targeted to-one
unlinking clears the relationship only if its current target matches.

## Provenance

[`docs/clog-source.json`](docs/clog-source.json) records the file hashes and Clog commit
the source was extracted from; original licensing is preserved in
[CLOG-LICENSE](CLOG-LICENSE).
