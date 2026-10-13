# Where the Rust compiler differs from the PHP one

The Rust `eleph` compiler in `compiler/` is measured against the PHP compiler in
`packages/schema`, which stays frozen as the migration oracle until parity is proven
(see [PLAN.md §21](PLAN.md)). The bar is byte-identical output for valid specs and the same
errors, by code, file and pointer, for invalid ones.

This file lists every place the two **deliberately** disagree. Each is a bug in one of the
two compilers rather than a feature; the "Fix" column says which. Anything not listed here
is a parity failure, and `cargo test` in `compiler/` fails on it.

How parity is checked: `tools/compare-compiler --refresh` runs the PHP stages over every
fixture in `packages/schema/tests/fixtures` and commits the results to
`compiler/tests/oracle/`. The Rust tests read those snapshots, so Rust CI needs no PHP, and
`tools/compare-compiler` (no flag) fails when the snapshots no longer match PHP.

| # | Difference | Why | Fix |
|---|---|---|---|
| 1 | **Schema errors.** PHP reports only the first `spec.invalid` per document; Rust reports every failing location. The oracle raises opis's `maxErrors` so the snapshot holds all the errors PHP's validator can find, and Rust must report each of those locations and may report more. Every other error code must match exactly. | opis stops at its first error by default, which breaks "errors accumulate". Even with the limit raised it does not descend into an object after its first failing keyword, where jsonschema reports each failing leaf (`/fields/count/type` as well as `/fields`). Imitating opis's keyword order would add code to reproduce a limitation. | PHP: set `maxErrors` on the validator. |
| 2 | **Empty maps.** `fields: {}` is valid in Rust and rejected by PHP ("must be an object"). | PHP cannot tell an empty map from an empty list, so `Helper::toJSON` hands the schema a list. An empty map is a map. | PHP: keep empty maps as objects when handing the document to the schema. |

## YAML typing is Symfony's, on purpose

saphyr follows the YAML 1.2 core schema; the PHP compiler's parser, symfony/yaml, does not.
They disagree on scalars that change a spec's meaning, so `compiler/src/spec/scalar.rs`
types plain scalars from their text by Symfony's rules (`Inline::evaluateScalar`) and
saphyr is only asked to parse structure:

- `007` and `-0` stay strings; `1_000` is the number 1000.
- An integer that does not fit in 64 bits stays a string.
- `2024-01-31` and `2024-01-31T10:00:00Z` are Unix times in UTC (a float with a fractional
  second), because that is what PHP produces.
- `0o14` and `0x1F` are numbers; `0b101` and `0X1F` are strings; `yes`, `no` and `on` are
  strings.
- Quoted and block scalars are always strings.

This is parity, not preference: a spec must compile to the same bytes either way. It is
worth revisiting when the PHP compiler is retired.

## Stricter in Rust, with an error rather than a guess

These reject documents where the PHP parser also fails, or where there is no safe reading.
They are in the oracle (`loader-errors`) and match.

- A file with two YAML documents, duplicate keys in a mapping, a complex (non-scalar) key,
  or a tag other than `!!str` and `!!float` is `spec.unparseable`.
- `.inf` and `.nan` cannot be a spec value, because JSON cannot hold them.

## The command line

The Rust `eleph` runs `fmt`, `validate` and `generate` with the same flags, exit codes and
words as the PHP one, so scripts and CI do not change when the binary under them does.
`tools/test-rust-cli` runs both on one project and requires the same exit codes, a
byte-identical generated tree, and the same problems named for a broken spec.

What differs is presentation only:

- **No coloured boxes.** PHP's console library draws padded, coloured blocks. Rust prints the
  same words as `[ERROR]` and `[OK]` lines, never coloured. `--no-ansi`, `-n` and `--ansi` are
  accepted and change nothing.
- **A project with no `eleph.json`** is reported on stderr with exit 1 for `fmt` and `generate`
  (PHP prints an uncaught-exception trace); `validate` reports it on stdout with a hint, as PHP
  does.
- **`fmt --check`** is accepted for symmetry with `generate --check`. `fmt` only ever reports,
  as in PHP, so the flag changes nothing.
- **Finding `eleph-codegen`.** PHP looks beside the running script and in the nearest
  `vendor/bin` above its own source. Rust looks beside its own binary and in the nearest
  `vendor/bin` above the *project*, which is where a Composer-installed project has it. An
  explicit `"codegen"` path and `PATH` work the same. `eleph install` (step 8) adds a fifth place.
- **Handle patterns.** A driver's `storage.handle.pattern` is PCRE in PHP and runs on the
  `regex` crate here, which has no lookaround, backreferences or possessive quantifiers. A
  pattern it cannot read is reported once, when the builders are described, naming the builder
  and saying why; PHP would run it. Drivers declare simple anchored classes today, so none is
  affected.

## `eleph check` is delegated, not ported

The PHP `check` loads the PHP target's `class-map.php`, autoloads the generated classes and runs
each `*/verify.php`. That can only work in PHP, so the Rust `check` does not port it. It asks
`eleph-codegen targets` for each target's output directory and `eleph-codegen describe` for its
checker (`provides.check.command`, with `{project}` and `{output}` filled in), runs it with the
project as the working directory, and reads one `{"severity", "message"}` JSON object per stdout
line. So it differs from PHP deliberately:

- it checks every target that has a checker, not only `php`;
- a project with no PHP target works, which is the point;
- a target with no checker is a warning, and a run that checked nothing fails (PHP failed only for
  a missing `php` target);
- an error signal, a non-zero exit, a line that is not a signal and a checker that cannot be started
  each fail the gate (a non-zero exit that already printed an error signal is not counted twice);
- output adds a `Checking <target> (<program>)` line per checker.

Until `elephentity-codegen-php` answers `provides.check` (step 6) a PHP project has no checker, so
the two steps merge together. The PHP `CheckCommand` is unchanged and stays as the oracle; the PHP
`Installed` parses `provides.check` so both compilers read `describe` the same way.

Everything else the PHP CLI does is ported: the compiler up to the request `eleph generate`
sends, compared byte for byte; `fmt`, `validate` and `generate`; and the `describe` handshake that
supplies integrations, patterns, types and storage rules.

## Not part of the compiler

`EdgePlanner` decides physical edge placement for a storage manifest. It lives in the
builders (the WordPress builder's `src/Sql/EdgePlanner.php`), nothing in the IR or on the
wire carries a placement, and so the compiler never calls it. It is ported with the
builders that need it.

## `eleph install` has no PHP counterpart

Composer installs the PHP `eleph` and its builders, so the PHP CLI never fetched anything and has
no `install` command. The Rust one adds `eleph install [--check]` (see `docs/PLAN.md`, "Installing
without PHP"), the `"name@version"` pin, and the `builders` and `codegen@version` keys it reads
from `eleph.json`. None of it changes what `fmt`, `validate`, `generate` or `check` do. The PHP
entry point is now `packages/cli/bin/eleph-php`; `bin/eleph` launches the Rust binary.
