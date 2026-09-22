# Standalone packages (0.11)

`elephentity/elephentity` is now a metapackage requiring only `elephentity/runtime`.
It installs no compiler, CLI, generators, memory adaptor, WordPress or WPGraphQL code.

**Upgrade warning:** upgrading the old bundle can remove its compiler commands and,
for older bundled releases, WordPress integrations. Declare the packages your project
uses explicitly before updating. Composer ignores a dependency's `require-dev`.

```sh
composer require elephentity/runtime elephentity/memory
composer require --dev elephentity/cli elephentity/codegen elephentity/codegen-php
```

For WordPress, replace memory with these explicit dependencies:

```sh
composer require elephentity/wordpress
composer require --dev elephentity/codegen-wordpress
```

For GraphQL also install `elephentity/wpgraphql` at runtime and
`elephentity/codegen-wpgraphql` for development. Schema is a CLI dependency; projects
using the compiler API directly can require `elephentity/schema` for development.
Remove `elephentity/elephentity` from require-dev once it has been replaced by CLI.

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
