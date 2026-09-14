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
./tools/php vendor/bin/phpunit --filter PipelineTest   # the four gates, end to end, on the memory driver
```

For the four gates against a real spec and real builders — including the WordPress and
WPGraphQL ones — see `clog` in
[elephentity-examples](https://github.com/hsimah-services/elephentity-examples); it is
a separate repository now, not something this one can run against.

`composer ci` must pass before committing. PHPStan runs at **level max** and there are
no baseline exclusions — the framework's whole claim is that generated code is provably
typed, so an exception here undermines the product.

## Layout

| Package | Ships? | Holds |
|---|---|---|
| `schema/` | dev | spec parsing, JSON Schemas, pattern resolution, the IR, its wire format |
| `runtime/` | yes | storage port, unit of work, verification, loaders |
| `memory/` | yes | the neutral driver — no physical schema, no platform |
| `cli/` | dev | the `eleph` command |

**This repository has no platform adaptor.** WordPress lives in
[`elephentity-wordpress`](https://github.com/hsimah-services/elephentity-wordpress),
WPGraphQL in
[`elephentity-wpgraphql`](https://github.com/hsimah-services/elephentity-wpgraphql) —
both moved out in elephentity#79, so a project installs WordPress by adding a
dependency, not by removing one. `tools/check-architecture.php` enforces that no
package here references WordPress at all. It uses the tokenizer, so prose in a doc
comment is fine and a real call is not.

`packages/runtime` stays here — `schema`, `cli` and `memory` all build on it
in-process — but is also mirrored, read-only, to
[`elephentity-runtime`](https://github.com/hsimah-services/elephentity-runtime) on
every push, so `elephentity/runtime` is installable on its own. Never edit that
mirror's `src/` or `tests/` directly; PRs go here.

**The code generator is not in this repository.** `eleph generate` compiles the specs and
pipes the IR to [`eleph-codegen`](https://github.com/hsimah-services/elephentity-codegen), which
resolves a project's builders — [`eleph-gen-php`](https://github.com/hsimah-services/elephentity-codegen-php)
and any others — runs them, and signs and writes what they return. Both are dev
dependencies here so that `PipelineTest` can run; a project installs them itself. The
generator is going to be rewritten in Rust, which is why it is a separate program
rather than a package.

**Worked examples live in their own repository too.**
[`elephentity-examples`](https://github.com/hsimah-services/elephentity-examples) holds
`clog`, a real WordPress plugin built on this framework, tested end to end against real
builders and adaptors — that is where a runtime class renamed out from under a builder
actually gets caught, not here.

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

Elephentity is eight repositories that talk over a wire format, not a shared
classpath — so nothing type-checks across the gap, and a builder or adaptor that falls
behind the compiler fails at run time rather than at build time.

**[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses.** If
you changed something on it, open an issue on each repository it reaches, before or with
the push. [`.llms/README.md`](.llms/README.md) has the rule and
[`.llms/issue-template.md`](.llms/issue-template.md) the shape. Everything depends on
`dev-main`, so a contract change that lands alone breaks somebody's build that afternoon.

## Before you commit

- `./tools/php composer ci`
- If you changed anything a builder or adaptor reads — the IR, a runtime class it names,
  a wire shape — regenerate `clog` in
  [elephentity-examples](https://github.com/hsimah-services/elephentity-examples) and
  commit the diff there. If the change was to the generator itself, it is in another
  repository and `composer update` for the package that changed comes first
- If you made a decision the plan does not cover, add it to `docs/PLAN.md` — the
  reasoning is the artifact, not the code
