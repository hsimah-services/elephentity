# Writing a builder

A **builder** is a program that turns an Elephentity spec into code in one language.
Elephentity compiles the spec; your builder decides what the output looks like. It can
be written in anything that can read JSON on stdin and write JSON on stdout.

The design and its reasoning live in [PLAN.md](PLAN.md) §15. This is the contract.

## The contract

```
eleph generate
  → compiles specs to the IR
  → for each target: runs the builder, request JSON on stdin
  ← builder writes response JSON on stdout, exits 0
  → eleph signs, writes and diffs the files
```

Three rules, and they are the whole of it:

1. **Read one JSON object from stdin. Write one JSON object to stdout. Exit 0.**
2. **Never touch the filesystem.** Return paths and bodies; `eleph` writes them. This is
   not a style preference — the signing that locks generated files happens after you
   return, and a builder that writes its own files is a builder whose output is not
   locked.
3. **Diagnostics go to stderr.** stdout is the response and nothing else. A stray
   `console.log` is a protocol error.

Exit non-zero to fail the build; whatever you wrote to stderr is shown to the user.

## The request

```json
{
  "elephentity": 1,
  "irVersion": "1.0",
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
- `target` — the key this target is configured under.
- `config` — your target's block from `eleph.json`, minus nothing. Elephentity does not
  know what your settings mean; validate them yourself and report problems in `errors`.
- `outputDirectory` — where your files will land, relative to the project. Informational:
  the paths you return are relative to it.
- `schema` — the IR.

## The response

```json
{
  "elephentity": 1,
  "irVersion": "1.0",
  "headerStyle": "line-comment",
  "extensions": ["ts"],
  "files": [{ "path": "post/Post.ts", "body": "export interface Post {}\n" }],
  "errors": []
}
```

- `headerStyle` — how your language comments. `php` or `line-comment`. Elephentity
  renders the signed header itself; you supply the body only, with no header of your own.
- `extensions` — the file extensions you own, without dots. **This is what you are
  allowed to delete.** Regenerating sweeps files the spec no longer produces, scoped to
  these, so a target that owns `ts` can never remove a neighbour's output.
- `files` — `path` is relative to `outputDirectory` and may not contain `..`. `body` is
  the complete file below the header, already formatted; run your own formatter, since
  Elephentity will not.
- `errors` — a list of strings. Non-empty means the build fails and no file is written,
  including other targets' files. Report *every* problem you found, not the first.

## Versions

There is **no compatibility guarantee before 1.0**. When the IR changes, builders change
with it. In exchange, the version check is a hard refusal in both directions rather than
a warning — a builder that half-understands the IR emits subtly wrong code, and
Elephentity then *signs* it, which is the worst thing this system can do. Refusing to
build is strictly better.

Declare exactly the versions you support and reject the rest:

```js
if (request.elephentity !== 1 || request.irVersion !== "1.0") {
  process.stderr.write(`eleph-gen-ts speaks IR 1.0, got ${request.irVersion}\n`);
  process.exit(1);
}
```

## Reading the IR

Two shapes worth knowing before you write types for it:

- **Collections keyed by name are JSON objects**, not arrays: `schema.entities`,
  `entity.fields`, `entity.edges`, `query.arguments`. Ordering is declaration order.
  `trigger.events` is the exception — a list, because order is execution order.
- **Enums are strings.** `cardinality` is `"one"` or `"many"`; `onDelete` is
  `"restrict"`, and so on.

An empty map is `{}` and an empty list is `[]`, so you can type them straightforwardly.

## Being installed

Add the target to `eleph.json`:

```json
{
  "spec": "spec",
  "targets": {
    "php": { "output": "generated", "namespace": "App\\Entity", "typeNamespace": "App\\Type" },
    "ts":  { "output": "web/generated", "builder": "eleph-gen-ts", "style": "esm" }
  }
}
```

`builder` is what makes a target external; without it, the target must be built into the
installation. Every target needs **its own output directory** — two targets sharing one
is refused, because generating either would delete the other's files.

**Elephentity resolves builders and never fetches them.** It looks in three places, in
order:

1. an explicit path, if `builder` contains a `/` — relative to the project, or absolute;
2. `tools/builders/<builder>` — override the directory with a top-level `"builders"` key;
3. anywhere on `PATH`.

How your builder gets there is your business: `npm install`, a git checkout, a symlink, a
downloaded binary. Elephentity is not a package manager and will not become one. Make it
executable — a non-executable file at the right path is the most common failure.

## A reference implementation

`packages/codegen-php/bin/eleph-gen-php` is the PHP target running as a builder, and it
is short. Elephentity generates PHP through the same `PhpTarget` either in process or
over this protocol, and two tests generate the same spec both ways and diff the bytes —
so the reference implementation is verified to be a faithful use of the contract rather
than merely a plausible one.

## Trying it by hand

A builder is just a program, so you can drive it without Elephentity:

```bash
eleph generate --project examples/clog --targets php   # the real thing
echo '{"elephentity":1,"irVersion":"1.0","target":"ts","config":{},
       "outputDirectory":"out","schema":{}}' | ./tools/builders/eleph-gen-ts
```

The second will fail on the empty schema, which is the point: you should be able to see
exactly how, and the error should say what was missing.
