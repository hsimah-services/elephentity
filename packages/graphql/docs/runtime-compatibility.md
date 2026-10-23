# Runtime compatibility for 0.1.0-alpha.3

> **Historical record.** This validated the 0.1.0-alpha releases against runtime 0.11–0.12 from the
> separate `elephentity-sqlite` and `elephentity-graphql-php` checkouts, using `tools/test-clog.py`
> (since deleted). The packages now release
> with the monorepo's PHP family and require `elephentity/runtime ^0.17`; see the package README.

Validated on 2026-10-10 with Clog commit
`213dc165944d5111c003ee36dccb51bfbf78758e`, including its generated PHP entities,
SQLite installation/storage manifest and GraphQL manifest.

Runtime 0.12.0 changes no API either integration uses. No integration source changes
were needed. Both integration packages now require
`elephentity/runtime ^0.10 || ^0.11 || ^0.12`.

Runtime 0.10.0 and 0.11.0 (the same commit) fail Clog's deletion traversal case with
`FOREIGN KEY constraint failed`. That is a runtime bug, fixed in 0.11.1, so the
matrix now covers 0.11.1 and 0.12.0. Use runtime 0.11.1 or later.

## Validation

Run from a workspace containing the two integration checkouts:

```sh
python3 elephentity-sqlite/tools/test-clog.py ~/Projects/clog
```

The runner builds a disposable Composer project from Clog's production requirements
and autoload mappings, with exact runtime versions and local path repositories for
the candidate integrations. It performs `composer update --dry-run`, installs fresh
dependencies, verifies the lockfile versions and runs each suite without network
access. It never reuses Clog's vendor directory or aliases runtime versions.

| Check | Runtime 0.11.1 | Runtime 0.12.0 |
| --- | --- | --- |
| Composer resolution and installation | Pass | Pass |
| Adapter conformance: all four relationship kinds | Pass | Pass |
| Schema upgrades, interruption recovery and refusal rollback | Pass | Pass |
| SQLite CRUD, policies, GraphQL pagination/Node/mutations, cascades | Pass | Pass |
| Deletion traversal with mismatched/colliding IDs | Pass | Pass |
| Password actions and account administration | Pass | Pass |
| HTTP authentication, CSRF, sessions, GraphQL, compiled deep links | Pass | Pass |

Environment: PHP 8.3.33, webonyx/graphql-php 15.38.0,
psr/container 2.0.2 and psr/log 3.0.2, using `localhost/clog-php:8.3-rust`.
The candidate packages are assigned `0.1.0-alpha.3` by Composer's path repository
metadata. This validates the candidates before publication; it does not establish
that the version is available from Packagist.

## Release validation

After publishing **both** integration packages as `v0.1.0-alpha.3`, run:

```sh
python3 elephentity-sqlite/tools/test-clog.py ~/Projects/clog --package-version 0.1.0-alpha.3
```

This disables the path repositories and repeats the same matrix with published
artifacts. Consumers can then update their lockfiles to runtime 0.12 and rerun their
application checks. Browser tests and package-owned standalone coverage remain
separate from these Clog integration checks.
