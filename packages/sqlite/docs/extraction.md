# Clog extraction notes

## Observed on 2026-09-26

Source: `~/Projects/clog`, branch `feature/standalone-sqlite`.
The source package is `server/standalone/vendor/elephentity/sqlite`.
The application wires it in `server/standalone/src/Application.php` using a storage
manifest, field map and query compiler, then passes it to the existing RuntimeFactory.
`server/standalone/src/Schema.php` owns explicit installation and schema versioning.

Clog's integration and HTTP suites passed with both extracted packages substituted
into a disposable fixture. Coverage includes CRUD, nullable updates, uniqueness,
nested rollback, read/write policies, Relay pagination, Node identity, mutations,
cascades, foreign keys, session cookies and CSRF. This establishes Clog compatibility
for the captured implementation. The runtime adapter conformance harness also
passes for OneToOne, ManyToOne, OneToMany and ManyToMany; this is not exhaustive
coverage of concurrency or all pending-ID and unlink edge cases.

## Remaining work before a general release

1. Make connection settings configurable. `Database` currently hardcodes the
   `app_` prefix and `CLOG_NOCASE` collation, WAL, a 3-second busy timeout and FULL
   synchronous mode. Keep Clog's Unicode comparison behavior explicitly configurable.
2. Replace the transitional `%s`/`%d`/`%f` placeholder rewrite with native SQLite
   parameters. Its regex can rewrite text inside SQL literals and comments.
3. Implement SQLite DDL and migrations. `Column::definition()` now rejects
   auto-increment columns and requires an explicit migration. Clog installs tables
   through application-owned SQL. There is no generic SQLite schema installer yet.
4. Extend the passing runtime adapter conformance harness with package-owned tests
   for pending IDs, rollback on constraint failure and pagination boundaries.
   Reconcile the query compiler's 1000-row clamp with the adaptor's requested limit.
5. Validate mapped identifiers consistently. Query values are bound, but several
   adaptor/compiler paths interpolate manifest identifiers between backticks.
6. Define SQLite manifest generation as a separate target beside PHP. Clog currently
   has a manually adapted manifest in `server/standalone/manifests/storage.php`.
   Keep SQLite-specific DDL out of the PHP entity generator and core runtime.
7. Validate Composer installation, runtime version compatibility and standalone CI
   before tagging or asking Clog to replace its bundled fork with this package.

## Keep in Clog

Inventory queries and totals, entity policies, users and roles, sessions, CSRF,
HTTP routing, Nginx/FPM configuration, application schema upgrades and packaging.
The library should expose the storage and transaction primitives these features use.

## Watching the developing source

Poll `tools/clog-status.py` during the active session. Review source changes before
importing them; avoid overwriting local package work or modifying Clog's checkout.
Changes outside the two packages (especially Application, Schema, GraphQL, manifests
and tests) must also be reviewed because they may reveal missing generic behavior.
Do not infer that Clog has finished from an unchanged poll or a passing test run.

## Source commit verified

Clog committed the standalone implementation as `fb57b3c`. Every imported library
file matches that commit. The disposable integration and HTTP suites also passed
with Clog's updated manifests and viewer. Development continues on the same branch.

## Upstream scope

[elephentity#89](https://github.com/hsimah-services/elephentity/issues/89) tracks
SQLite and schema generation. It also records a core-runtime deletion-planner
direction issue: Clog currently uses a narrow `DependentReadStorage` wrapper.
Resolve that behavior in the runtime instead of duplicating it in this adapter.
