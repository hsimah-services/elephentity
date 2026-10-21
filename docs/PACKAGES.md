# PHP packages

What to install from Composer, and how the versions fit together. For the full release process see
[RELEASING.md](RELEASING.md#php-packages).

## What to install

```sh
curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh
composer require elephentity/runtime elephentity/memory
composer require --dev elephentity/codegen elephentity/codegen-php
```

The first line installs the `eleph` compiler, a single binary that needs no PHP.

For a real database, replace `memory` with SQLite:

```sh
composer require elephentity/sqlite
composer require --dev elephentity/codegen-sqlite
```

For GraphQL also install `elephentity/graphql` at runtime and `elephentity/codegen-graphql-php` for
development. `elephentity/elephentity` is a metapackage that requires only the runtime; you do not
need it.

`elephentity/cli` and `elephentity/schema`, the retired PHP compiler, stop at v0.14.2: remove them
and install `eleph` with `install.sh` instead. Composer ignores a dependency's `require-dev`, so
declare every package your project uses yourself.

Generator executable paths in `eleph.json` (`vendor/bin/eleph-gen-php`, …) are where Composer puts
them. Build the Rust generators after installing them, as described in
[GETTING_STARTED.md](GETTING_STARTED.md).

## Two kinds of version

**The package family: `runtime`, `memory`, `sqlite`, `graphql`.** These four share one version and
are released together, so `0.17.x` of any of them works with `0.17.x` of the others. Require the
same minor of each (`"elephentity/runtime": "^0.17"`, `"elephentity/sqlite": "^0.17"`). Before 1.0,
a minor means something in the family breaks, so moving to a new minor is a decision to take for
all four at once; a patch breaks nothing.

**The builders: `codegen`, `codegen-php`, `codegen-sqlite`, `codegen-graphql-php`,
`codegen-tsquid`.** Each has its own version, because each is a program with a wire protocol with
the compiler rather than a library. Take the newest that supports your family minor; a builder
caps the runtime at the minor it was tested with.

Versions before `0.17.0` are not on this line: `runtime` and `memory` followed the compiler's
version, and `sqlite` and `graphql` had their own alphas. Packagist shows `runtime` 0.15.0, 0.16.0
and 0.16.1, which have no change in them; start from 0.17.0.

## Where the source is

Everything is in [hsimah-services/elephentity](https://github.com/hsimah-services/elephentity) under
`packages/` and `builders/`. Each Composer package is mirrored, read-only, to its own repository,
because Packagist publishes one package per repository. Do not edit a mirror; pull requests go to
`elephentity`.

For framework development, clone that repository and run `composer install`. Its root-only
development autoload and tools test all package sources together; consumers of the metapackage
receive only its production runtime dependency.
