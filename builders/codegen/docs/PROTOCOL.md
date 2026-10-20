# The protocol

`eleph-codegen` sits between the Elephentity compiler and one or more builders. It
speaks to each over a pipe, and both exchanges follow the same rules.

```
eleph generate                       Elephentity — parses specs, resolves patterns,
  │ compiles specs → IR              produces the IR. PHP.
  ▼
eleph-codegen generate               this program — resolves builders, runs them,
  ├→ eleph-gen-php   ─ files         signs, writes, diffs. Knows no language.
  ├→ eleph-gen-ts    ─ files
  ▼
generated/                           builders — IR in, source out. Any language.
```

Nothing in the middle understands the IR. It arrives encoded, is forwarded to each
builder unchanged, and is never decoded here — which is why adding a field to the IR
needs a new compiler and a new builder, and no release of this program at all.

## Two things a builder is asked

Every exchange carries a `request` field saying which:

| `request` | Asked | Answers |
|---|---|---|
| `describe` | before a spec is compiled | what this builder provides |
| `generate` | after | the files |

`request` is optional and absent means `generate`, so the shape below is what it always
was. **A builder must answer both.** Describe exists because the compiler cannot
validate `integrations: { wpgraphql: { singular: … } }` until it knows what keys
`wpgraphql` accepts, and the package that owns that answer is the one that also
generates the GraphQL manifest — so it is asked first and the spec is compiled second.

## Three rules for a builder

1. **Read one JSON object from stdin. Write one JSON object to stdout. Exit 0.**
2. **Never touch the filesystem.** Return paths and bodies; `eleph-codegen` writes them.
   This is not a style preference — the signing that locks generated files happens after
   you return, and a builder that writes its own files is a builder whose output is not
   locked.
3. **Diagnostics go to stderr.** stdout is the response and nothing else. A stray
   `console.log` is a protocol error.

Exit non-zero to fail the build; whatever you wrote to stderr is shown to the user.

## The describe request

```json
{ "elephentity": 1, "irVersion": "1.2", "request": "describe", "target": "ts",
  "config": { "output": "web/generated", "style": "esm" } }
```

No schema: a description is what the compiler needs in order to compile, so it has to be
answerable before a spec has been read.

**`config` is optional and additive.** It is the same object `generate` receives (the
target's keys in `eleph.json`, minus the ones this program owns), because some of what a
builder provides is the project's to say. A C# builder's `provides.check` is the command
for the project's own contract-test project, which only the project knows. A builder that
needs nothing from it ignores it, and one that reads it must still answer when it is absent
and when it is unusable: reporting a bad configuration is `generate`'s job.

## The describe response

```json
{
  "elephentity": 1,
  "irVersion": "1.2",
  "provides": {
    "integrations": {
      "wpgraphql": {
        "description": "Exposes entities through WPGraphQL.",
        "entityConfig": { "singular": { "type": "string" } }
      }
    },
    "drivers": ["wordpress"]
  }
}
```

`provides` is **opaque to this program**. It is forwarded to the compiler exactly as
the IR is forwarded to you, and for the same reason: the compiler is the only thing
that knows what an integration is, so a builder that starts providing a new kind of
thing needs a new compiler and no release of `eleph-codegen`. What the keys above mean
is Elephentity's business — see its own docs.

A builder that provides neither returns `"provides": {}`. That is an answer, not a
failure: a language generator is expected to provide nothing.

## The builder request

```json
{
  "elephentity": 1,
  "irVersion": "1.2",
  "request": "generate",
  "target": "ts",
  "config": { "style": "esm" },
  "outputDirectory": "web/generated",
  "schema": { "project": {}, "entities": {}, "types": {} }
}
```

Everything except `schema` is the **envelope** — metadata about the payload. Read
`elephentity` and `irVersion` *first* and refuse anything you do not understand, before
looking at a single entity. That is the whole reason the versions sit outside the
payload.

- `elephentity` — protocol version. Currently `1`.
- `irVersion` — the IR's version, which moves independently of the protocol's.
- `request` — `generate`. Absent means the same.
- `target` — the key this target is configured under.
- `config` — your target's block from `eleph.json`, minus nothing. Nothing upstream
  knows what your settings mean; validate them yourself and report problems in `errors`.
- `outputDirectory` — the resolved absolute directory where your files will land. Informational:
  the paths you return are relative to it.
- `schema` — the IR.

## The builder response

```json
{
  "elephentity": 1,
  "irVersion": "1.2",
  "headerStyle": "line-comment",
  "extensions": ["ts"],
  "files": [{ "path": "post/Post.ts", "body": "export interface Post {}\n" }],
  "errors": []
}
```

- `headerStyle` — how your language comments. `php` or `line-comment`. The signed header
  is rendered here; you supply the body only, with no header of your own.
- `extensions` — the file extensions you own, without dots. **This is what you are
  allowed to delete.** Regenerating sweeps files the spec no longer produces, scoped to
  these, so a target that owns `ts` can never remove a neighbour's output.
- `files` — `path` is relative to `outputDirectory` and may not contain `..`. `body` is
  the complete file below the header, already formatted; run your own formatter, since
  nothing downstream will.
- `errors` — a list of strings. Non-empty means the build fails and no file is written,
  including other targets' files. Report *every* problem you found, not the first.

## The compiler request

The upstream half, for anyone writing a compiler rather than a builder. It arrives on
`eleph-codegen generate`'s stdin.

```json
{
  "elephentity": 1,
  "irVersion": "1.2",
  "schema": { },
  "files": { "php": [{ "path": "storage-manifest.php", "body": "return [];\n" }] }
}
```

- `schema` — the IR, forwarded to every builder untouched.
- `files` — optional. Output the compiler produced itself, addressed to a target and
  written alongside that target's files. It exists because Elephentity compiles the
  WordPress storage manifest and the GraphQL manifest from code that knows what
  WordPress is, which is exactly the knowledge this program is built not to have. A
  contributed file is signed identically to a generated one: the signature says
  "machine-owned", not "produced by a builder".

The same path rule applies. The compiler is trusted more than a builder, but not so much
that a bug in it should be able to write outside the tree.

## Versions

There is **no compatibility guarantee before 1.0**. When the IR changes, builders change
with it. In exchange, the version check is a hard refusal in both directions rather than
a warning — a builder that half-understands the IR emits subtly wrong code, and it is
then *signed*, which is the worst thing this system can do. Refusing to build is strictly
better.

Declare exactly the versions you support and reject the rest:

```js
if (request.elephentity !== 1 || request.irVersion !== "1.2") {
  process.stderr.write(`eleph-gen-ts speaks IR 1.2, got ${request.irVersion}\n`);
  process.exit(1);
}
```

Each side declares its own versions rather than reading them from a shared library.
Three programs that agreed by sharing a constant would be one program in three files;
declaring separately is what makes a disagreement detectable.

## Reading the IR

Two shapes worth knowing before you write types for it:

- **Collections keyed by name are JSON objects**, not arrays: `schema.entities`,
  `entity.fields`, `entity.edges`, `query.arguments`. Ordering is declaration order.
  `sideEffect.events` is the exception — a list, because order is execution order.
- **Enums are strings.** `cardinality` is `"one"` or `"many"`; `onDelete` is
  `"restrict"`, and so on.

An empty map is `{}` and an empty list is `[]`, so you can type them straightforwardly.

## Being installed

Add the target to `eleph.json`:

```json
{
  "spec": "spec",
  "targets": {
    "php": { "output": "generated", "builder": "vendor/bin/eleph-gen-php",
             "namespace": "App\\Entity", "typeNamespace": "App\\Type" },
    "ts":  { "output": "web/generated", "builder": "eleph-gen-ts", "style": "esm" }
  }
}
```

**Every target names a builder.** Nothing is built in — this program generates nothing
itself — so a target without one is a target nothing can produce, and `eleph.json`
refuses it when it loads.

Every target needs **its own output directory**. Two targets sharing one is refused,
because generating either would delete the other's files. Paths are resolved against
the project, including existing symlinks and `.`/`..` segments, before checking ownership.

One target's directory **may sit inside another's**, and the sweep respects it. That is
how a driver's manifests reach the tree they belong to: they are PHP, loaded by the PHP
runtime, and the PHP builder cannot produce them — so they come from a target of their
own writing to `generated/wordpress`. The exclusion is computed from every configured
target rather than the ones a run selected, so narrowing with `--targets` cannot delete
the tree it left out.

**Builders are resolved, never fetched.** Three places, in order:

1. an explicit path, if `builder` contains a `/` — relative to the project, or absolute;
2. `tools/builders/<builder>` — override the directory with a top-level `"builders"` key;
3. anywhere on `PATH`.

**A builder may carry a pin: `"builder": "eleph-gen-csharp@0.1.0"`.** The `@version` is for
`eleph install`, which fetches that release into `tools/builders/`; this program only strips it
and looks for `eleph-gen-csharp`, so it still fetches nothing and checks nothing about the
version. A `builder` containing a `/` is a path and is never split (an `@` is legal in one).
`eleph-codegen targets` reports the `builder` as written, and the optional `"source"` of the
target (`"owner/repo"`, where its releases are published, for a builder `eleph install` does not
know). `source` belongs to this program and the installer: it is not passed on to the builder.

How your builder gets there is your business: `composer require`, `npm install`, a git
checkout, a symlink, a downloaded binary. This is not a package manager and will not
become one. Make it executable — a non-executable file at the right path is the most
common failure, and `eleph-codegen doctor` exists to say so.

## Trying it by hand

A builder is just a program, so you can drive it without any of this:

```bash
echo '{"elephentity":1,"irVersion":"1.2","target":"ts","config":{},
       "outputDirectory":"out","schema":{}}' | ./tools/builders/eleph-gen-ts
```

It will fail on the empty schema, which is the point: you should be able to see exactly
how, and the error should say what was missing.

And the orchestrator itself, without a compiler. It needs a project — an `eleph.json`
with a `targets` block — because that is what tells it which builders to run:

```bash
echo '{"elephentity":1,"irVersion":"1.2","schema":{"entities":{}},"files":{}}' \
  | eleph-codegen generate --project path/to/project
```

An empty `entities` map is a valid schema, so this generates a project's whole tree as
the spec having nothing in it — which is a quick way to see exactly which files a target
owns, since every one of them is about to be swept.
