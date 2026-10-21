# Standalone packages (0.11)

`elephentity/elephentity` is now a metapackage requiring only `elephentity/runtime`.
It installs no compiler, CLI, generators, memory adaptor or platform adaptor code.

**Upgrade warning:** upgrading the old bundle can remove its compiler commands. Declare the
packages your project uses explicitly before updating. Composer ignores a dependency's `require-dev`.

```sh
curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh
composer require elephentity/runtime elephentity/memory
composer require --dev elephentity/codegen elephentity/codegen-php
```

The first line installs the `eleph` compiler, a single binary that needs no PHP.

For a real database, replace memory with these explicit dependencies:

```sh
composer require elephentity/sqlite
composer require --dev elephentity/codegen-sqlite
```

For GraphQL also install `elephentity/graphql` at runtime and
`elephentity/codegen-graphql-php` for development. `elephentity/cli` and
`elephentity/schema`, the retired PHP compiler, stop at v0.14.2: remove them, and install
`eleph` with `install.sh` instead. Remove `elephentity/elephentity` from require-dev too.

The CLI ships `eleph` and the neutral `eleph-gen-memory` builder. The memory adaptor
requires only runtime. Generator executable paths in `eleph.json` stay the same.
Build the Rust generators after installing them as described in GETTING_STARTED.md.

Schema, CLI and memory are released as 0.11.0. They use real version ranges: CLI needs
schema ^0.11 and codegen ^0.6; CLI and memory accept runtime ^0.10 or ^0.11.
The runtime behavior and IR 1.2 contract are unchanged from the 0.10 lifecycle release.

Sources remain in `packages/` in this repository. Schema, CLI and memory mirror all
package files, including resources and binaries; runtime retains its existing mirror
configuration and synchronizes src/tests. Main pushes synchronize sources, and source
tags synchronize the corresponding revision before tagging mirrors. Do not edit
mirrored package sources directly. Platform adaptors and generators remain independent.

For framework development, clone this repository and run `composer install`. Its
root-only development autoload and tools still test all package sources together;
consumers of the metapackage receive only its production runtime dependency.
