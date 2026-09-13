# Elephentity

An AI-native PHP framework that compiles human-readable specs into locked, signed
business logic. Read [docs/PLAN.md](docs/PLAN.md) first — it carries every design
decision and the reasoning behind it, and this file assumes it.
[docs/GLOSSARY.md](docs/GLOSSARY.md) defines the vocabulary, and
[docs/BUILDERS.md](docs/BUILDERS.md) is the contract a code generator implements.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, architecture, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
./tools/php packages/cli/bin/eleph generate --project examples/clog
./tools/php vendor/bin/eleph-codegen doctor --project examples/clog   # builders installed?
```

`composer ci` must pass before committing. PHPStan runs at **level max** and there are
no baseline exclusions — the framework's whole claim is that generated code is provably
typed, so an exception here undermines the product.

## Layout

| Package | Ships? | Holds |
|---|---|---|
| `schema/` | dev | spec parsing, JSON Schemas, pattern resolution, the IR, its wire format |
| `runtime/` | yes | storage port, unit of work, verification, loaders |
| `wordpress/` | yes | the one adaptor — the only package that may name `WP_*` |
| `wpgraphql/` | yes | IR → compiled GraphQL manifest |
| `cli/` | dev | the `eleph` command |

`tools/check-architecture.php` enforces that `schema`, `runtime` and `cli` reference no
WordPress symbol. It uses the tokenizer, so prose in a doc comment is fine and a real
call is not.

**The code generator is not in this repository.** `eleph generate` compiles the specs and
pipes the IR to [`eleph-codegen`](https://github.com/hsimah-services/elephentity-codegen), which
resolves a project's builders — [`eleph-gen-php`](https://github.com/hsimah-services/elephentity-codegen-php)
and any others — runs them, and signs and writes what they return. Both are dev
dependencies here so that `PipelineTest` and `examples/clog` can run; a project installs
them itself. The generator is going to be rewritten in Rust, which is why it is a
separate program rather than a package.

## Conventions that are load bearing

- **The spec carries no application namespaces.** `handler: true`, `verify: true`,
  `processors: true` and policy declarations all mean "generate the interface"; names
  come from `eleph.json`.
- **Storage placement is inferred, never declared.** If the framework can work it out,
  a human choosing it is a chance for two entities to disagree.
- **Errors accumulate.** Compilers, verifiers and planners collect problems and report
  them together. Nothing aborts on the first failure unless continuing would be
  meaningless.
- **Generated code is grouped by entity.** `Item/` holds everything for Item, and
  `Item/Contract/` holds exactly what a consumer must implement.
- **One source of truth per decision.** `EdgePlanner` decides edge placement for both
  the schema builder and the query compiler. `Names` — now in the PHP builder — decides
  class names, and `eleph check` reads them out of the generated `class-map.php` rather
  than calling `Names` itself: the generator is a separate program and one day another
  language, so the tree carries its own index. The `targets` block of `eleph.json` has
  one parser too, and it is `eleph-codegen`'s; `eleph check` asks it rather than reading
  the file again. All three were bugs before they were rules.

## Testing

Pure logic is tested directly; anything touching WordPress is kept thin enough that the
thin part needs no test. `packages/cli/tests/PipelineTest.php` runs all four gates end
to end and is what proves the layers are wired together at all.

`packages/schema/tests/fixtures/valid/` is the canonical spec exercising every
construct. Most tests compile it.

## Before you commit anything that crosses a repository boundary

Elephentity is three published programs that talk over a wire format, not a shared
classpath — so nothing type-checks across the gap, and a builder that falls behind the
compiler fails at run time rather than at build time.

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or with
the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape. Everything depends on
`dev-main`, so a contract change that lands alone breaks somebody's build that afternoon.

## Before you commit

- `./tools/php composer ci`
- If you changed anything the generator reads, regenerate `examples/clog/` and commit the
  result. If the change was to the generator itself, it is in another repository and
  `composer update elephentity/codegen-php` comes first
- If you made a decision the plan does not cover, add it to `docs/PLAN.md` — the
  reasoning is the artifact, not the code
