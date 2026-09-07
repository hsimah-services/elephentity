# Elephentity

An AI-native PHP framework that compiles human-readable specs into locked, signed
business logic. Read [docs/PLAN.md](docs/PLAN.md) first — it carries every design
decision and the reasoning behind it, and this file assumes it.
[docs/GLOSSARY.md](docs/GLOSSARY.md) defines the vocabulary.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, architecture, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
./tools/php packages/cli/bin/eleph generate --project examples/clog
```

`composer ci` must pass before committing. PHPStan runs at **level max** and there are
no baseline exclusions — the framework's whole claim is that generated code is provably
typed, so an exception here undermines the product.

## Layout

| Package | Ships? | Holds |
|---|---|---|
| `schema/` | dev | spec parsing, JSON Schemas, pattern resolution, the IR, its wire format |
| `codegen/` | dev | the target contract, the builder protocol, signing, writing |
| `codegen-php/` | dev | the PHP target: IR → locked PHP, in process or as `eleph-gen-php` |
| `runtime/` | yes | storage port, unit of work, verification, loaders |
| `wordpress/` | yes | the one adaptor — the only package that may name `WP_*` |
| `wpgraphql/` | yes | IR → compiled GraphQL manifest |
| `cli/` | dev | the `eleph` command |

`tools/check-architecture.php` enforces that `schema`, `codegen`, `codegen-php`,
`runtime` and `cli` reference no WordPress symbol. It uses the tokenizer, so prose in a doc comment is
fine and a real call is not.

## Conventions that are load bearing

- **The spec carries no application namespaces.** `handler: true`, `verify: true` and
  `processors: true` mean "generate the interface"; names come from `eleph.json`.
- **Storage placement is inferred, never declared.** If the framework can work it out,
  a human choosing it is a chance for two entities to disagree.
- **Errors accumulate.** Compilers, verifiers and planners collect problems and report
  them together. Nothing aborts on the first failure unless continuing would be
  meaningless.
- **Generated code is grouped by entity.** `Item/` holds everything for Item, and
  `Item/Contract/` holds exactly what a consumer must implement.
- **One source of truth per decision.** `EdgePlanner` decides edge placement for both
  the schema builder and the query compiler; `Names` decides class names for both the
  generator and the conformance checker. Both of those were bugs before they were
  rules.

## Testing

Pure logic is tested directly; anything touching WordPress is kept thin enough that the
thin part needs no test. `packages/cli/tests/PipelineTest.php` runs all four gates end
to end and is what proves the layers are wired together at all.

`packages/schema/tests/fixtures/valid/` is the canonical spec exercising every
construct. Most tests compile it.

## Before you commit

- `./tools/php composer ci`
- If you changed the generator, regenerate `examples/clog/` and commit the result
- If you made a decision the plan does not cover, add it to `docs/PLAN.md` — the
  reasoning is the artifact, not the code
