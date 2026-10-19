# elephentity-codegen-php

The PHP builder for [Elephentity](https://github.com/hsimah-services/elephentity).

Implemented in Rust. Build a checkout with `cargo build --release --locked`, or install
the executable on PATH with `cargo install --path . --locked`. The `bin/eleph-gen-php`
checkout launcher uses `target/release/eleph-gen-php` (or a debug build during development).
It never falls back to PHP. Installed Cargo binaries need neither PHP nor Cargo to run.

It reads one JSON request on stdin — the compiled spec, plus the target's configuration
— and writes one JSON response on stdout: a path and a body per file. It never touches
the filesystem. Signing and writing happen in
[elephentity-codegen](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen), after this exits.

```bash
echo '{"elephentity":1,"irVersion":"1.1","target":"php","config":{},
       "outputDirectory":"out","schema":{}}' | ./bin/eleph-gen-php
```

That fails on the empty schema, which is the point: it should be obvious how.

## Installing it

```bash
# From this repository:
cargo install --path . --locked

# Or, when using the Composer distribution:
composer require --dev elephentity/codegen-php
cargo build --release --locked --manifest-path vendor/elephentity/codegen-php/Cargo.toml
```

Use `"builder": "eleph-gen-php"` for a Cargo installation, or the Composer
launcher as shown below. Then name it in `eleph.json`:

```json
{
  "targets": {
    "php": {
      "builder": "vendor/bin/eleph-gen-php",
      "output": "generated",
      "namespace": "App\\Entity",
      "typeNamespace": "App\\Type"
    }
  }
}
```

`namespace` and `typeNamespace` are read and validated here, not upstream. Nothing else
in the pipeline knows what they mean.

## It depends on nothing of Elephentity's

Not the compiler, not the runtime, not the orchestrator. The IR types in `rust/ir.rs`
are an independent copy, and the runtime classes generated code refers to are strings in
`rust/runtime.rs` rather than imports. That is deliberate: a builder that had to
`composer require` the framework would be a builder no other language could write. The
version gate is what holds the copy in step — a mismatch is a refusal, never a silent
misread.

## Gate 4: `eleph check`

This builder answers `provides.check` in `describe`, so `eleph check` runs

```
eleph-check-php --project {project} --output {output}
```

for the `php` target, with `{project}` and `{output}` filled in. `eleph-check-php` is a Composer
`bin`, so it lands in `vendor/bin` beside `eleph-gen-php`, and `eleph` finds it the same way it
finds a builder: the nearest `vendor/bin` above the project, then beside the running `eleph`, then
`PATH`. It loads `<output>/class-map.php`, autoloads the generated classes (and the project's own
Composer autoloader, if there is one), runs every `<output>/*/verify.php` and prints one
`{"severity": "error|warning|info", "message": "..."}` per line. It exits non-zero if any is an
error, and an unreadable tree is an error signal rather than a crash or a pass. It needs PHP, which
is fine here: the tree it checks is PHP.

## Working on it

```bash
cargo fmt --check
cargo clippy --all-targets -- -D warnings
cargo test --locked
./tools/php composer ci
```

Rust sources live in `rust/`. Each builder owns its IR types and version gate; there
is no runtime dependency on the compiler or another builder. PHP in `src/` and the
`bin/eleph-gen-php-reference` executable is retained as a migration oracle for the
existing tests. Production entrypoints run Rust only. PHPStan still checks the reference
and acceptance tests at level max.

PHP declarations use [`php_codegen`](https://docs.rs/php_codegen/0.4.0/php_codegen/).
The crate owns formatting; there is no compatibility printer. File names, runtime
interfaces, method behavior, and class-map structure remain compatible.

`php tools/compare-rust.php` compares the frozen PHP responses and additional scenarios
against the Rust binary using parsed, name-resolved PHP ASTs. This permits formatting,
quote style, and trailing-comma differences while checking executable structure.

## The golden fixtures

`tests/fixtures/golden/*/response.json` pins the Rust output. The original PHP responses
are preserved as `response.reference.json`, unchanged, for the independent AST checks.
Only the formatting snapshots changed in this migration. The WordPress and WPGraphQL
builders retain their original output bytes.

Regenerate generated PHP once after upgrading, and commit the changed signed files.
The signer itself is unchanged; formatting changes naturally produce new digests.

PHP builder 0.7 targets runtime 0.13. Regenerate when upgrading: catalogues include nonnullable field metadata, and nullable verifier interfaces now accept nullable values. Implementations must widen those parameters. Create inputs seed defaults through their normal decoders; explicit values and pending action writes take precedence, while partial updates leave absent fields alone. Generated defaults and verification are exercised through Memory, SQLite and GraphQL in CI.

Declared JSON/datetime processors receive stored strings, as their generated scalar contracts promise; ordinary JSON/datetime fields still decode to arrays/date objects. Implementations that relied on the previous premature primitive decoding must update their read processors.

Actions expose `context()` as the entity's typed, read-only mutation context: handlers can read id, original and pending values while their setters remain restricted to declared writes. Buffers supplying reads must implement MutationContext (the runtime's Mutation already does); write-only custom buffers receive a clear error when requesting reads. Generated artifacts contain no trailing horizontal whitespace.
