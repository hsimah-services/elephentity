# elephentity-codegen-php

The PHP builder for Elephentity: the IR in on stdin, PHP source out on stdout.

Read [README.md](README.md), then `PROTOCOL.md` in elephentity-codegen — that is the
contract this implements, and this file assumes it.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter PhpTargetTest
```

`composer ci` must pass before committing. PHPStan runs at **level max** and there are no
baseline exclusions — the whole claim is that generated code is provably typed, so an
exception here undermines the product.

## Three rules, and they are the whole of it

1. **Read one JSON object from stdin. Write one JSON object to stdout. Exit 0.**
2. **Never touch the filesystem.** Return paths and bodies. Signing happens after this
   exits, so a builder that wrote its own files would produce output nothing had locked.
3. **Diagnostics go to stderr.** stdout is the response and nothing else.

## Depends on nothing of Elephentity's

| Was | Is now | Why |
|---|---|---|
| `Eleph\Schema\Ir\*` | `src/Ir/*`, a copy | a builder that requires the compiler is not pluggable |
| `Eleph\Runtime\*` via `::class` | `src/Runtime.php` constants | the generator emits these names; it does not need to load them |
| `Eleph\Codegen\Protocol` | `src/Protocol/*`, this side only | two implementations of one format is what makes it a protocol |

The copies are held in step by the version gate, not by a shared classpath.
`Envelope::IR_VERSION` is declared here and again in every other program; when they
disagree the build refuses, in both directions. `EnvelopeTest` checks the constant still
matches this repository's own `IrCodec::VERSION`, which catches the other direction.

**If a runtime class in `src/Runtime.php` is renamed, nothing here fails.** Generated code
stops compiling in the project that uses it, and no amount of type checking on this side
would have caught it. What catches it is regenerating Elephentity's worked example.

## Conventions that are load bearing

- **Errors accumulate.** `PhpConfig::problemsIn()` reports every bad key at once; a
  builder that gave up on the first would make the caller run the build once per mistake.
- **Bad config is a response with errors, not a crash.** Exit 0 with a populated `errors`
  list. A non-zero exit is for something that made generating impossible at all.
- **`Names` decides class names, once.** It is also what writes `class-map.php`, which is
  how `eleph check` resolves a spec name to a class without importing anything from here.
  That was a bug before it was a rule.

## Testing

`tests/GoldenTest.php` runs the real binary against committed request/response pairs.
Those fixtures are the acceptance suite for the Rust rewrite: they do not change, and the
new binary has to reproduce them exactly. `tests/PhpTargetTest.php` is the readable
version of the same thing — it asserts what each construct produces, so a failure names
the feature rather than a byte offset.

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
- If the generator's output changed, regenerate the golden fixtures and read the diff
- If the diff is not what you meant, it is what every project's tree is about to become
