# elephentity-codegen

The code generator for Elephentity. It is a separate program from the spec framework on
purpose: Elephentity compiles specs and stays PHP, this becomes Rust, and the only thing
between them is JSON on a pipe.

Read [docs/PROTOCOL.md](docs/PROTOCOL.md) first — it is the contract, in both directions,
and this file assumes it.

## Working in this repository

There is no local PHP. Everything runs in a container:

```bash
./tools/php composer ci          # style, static analysis, tests
./tools/php composer style:fix
./tools/php vendor/bin/phpunit --filter SomeTest
```

`composer ci` must pass before committing. PHPStan runs at **level max** and there are no
baseline exclusions.

## What this program is, and is not

| Does | Does not |
|---|---|
| read `eleph.json`'s `targets` block | read specs, or know what a spec is |
| resolve a builder to an executable | fetch, pin, checksum or cache one |
| run builders and pool their errors | generate code |
| ask builders what they provide | know what an integration or a driver is |
| sign, write and diff the tree | decode the IR, or understand an entity |

The last row is the load-bearing one, and the fourth is the same rule applied twice. The
IR arrives encoded, is forwarded untouched, and is never decoded here; a builder's
`provides` is forwarded to the compiler the same way. A change that adds a field to
either needs a new compiler and a new builder and no release of this.

## Conventions that are load bearing

- **The envelope's shape is frozen.** It may gain optional fields and nothing else. It is
  what both sides must agree on before anything else can be negotiated, so it cannot
  itself be negotiable.
- **Each side declares its own versions.** `Envelope::IR_VERSION` is a constant here and a
  constant again in Elephentity and again in every builder. Three programs agreeing by
  sharing a constant would be one program in three files; declaring separately is what
  makes a disagreement detectable, and `assertVersions()` is what turns it into a refusal.
- **A builder returns bytes and never writes.** Signing happens after it returns, so a
  builder that wrote its own files would produce output nothing has locked.
- **Errors accumulate.** Every target runs before anything is written, and their problems
  are pooled, so a project with two broken builders is told about both at once and a build
  that will fail writes nothing at all.
- **Deleting is scoped to the extensions a target declared.** Sweeping the whole output
  directory would be defensible — it is machine-owned — but it turns a mistyped `output`
  into data loss.

## Testing

`tests/Command/GenerateCommandTest.php` runs the real binary as a subprocess with a
request on stdin and asserts bytes on disk. It is deliberately not a class test: the
entrypoint is the contract, and these assertions are what a Rust rewrite has to satisfy
unchanged. It pins one signed header's exact digest, so a change to the header format
fails here first — which is correct, because that change invalidates every signature in
every tree Elephentity has ever written.

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
- If you changed the protocol, change `docs/PROTOCOL.md` in the same commit — it is the
  only description builders in other languages have
