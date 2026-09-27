# Deletion policies against SQLite

This optional suite exercises core runtime source against the released
`elephentity/sqlite v0.1.0-alpha.2` adapter. CI runs it on PHP 8.3 and 8.4.
It requires PDO SQLite and mbstring, plus the core's Composer dev dependencies.
With a sibling `elephentity-sqlite` checkout, run from the core repository:

```sh
vendor/bin/phpunit --bootstrap tools/integration/sqlite-bootstrap.php tools/integration/DeletionPlannerSQLiteTest.php
```

Set `ELEPH_SQLITE_SOURCE` to the adapter's `src` directory for another layout.
Both repositories must be mounted when running in a container.

The suite validates planned operations and persisted results with foreign keys
and one-to-one uniqueness enforced. It covers cascade, restrict and nullify for
all four cardinalities, both ends of join relationships, preserving unrelated
links, child-only deletion, and rollback when a pending mutation makes an initially
empty location occupied before the unit of work rechecks its deletion plan.

The Clog fixture deliberately collides IDs: Item 5 has Inventory 20/21, while
Inventory 5 belongs to Item 9. Restriction tests use occupied Location 7 and empty
Location 99. No application read wrapper participates in these checks.

## Clog without its workaround

To exercise generated entities, manifests and HTTP behavior against this runtime:

```sh
python3 tools/test-clog-runtime.py ~/Projects/clog
```

The runner copies Clog into a disposable directory, replaces its runtime source,
removes `DependentReadStorage` and its factory wrapper, and runs adapter conformance,
schema-upgrade, integration and HTTP suites. It requires Podman, Clog's installed
dependencies and client build, and `localhost/clog-php:8.3-rust` (or `--image`).
The original checkout is unchanged. It fails if the expected workaround is absent,
so changes to Clog's wiring require an explicit fixture review.

Validated with Clog `223d52b8fe2a1219361c13258f1b67ab6109ae59` and PHP 8.3.33.
These checks do not publish a runtime release or update Clog's dependency lockfile.
