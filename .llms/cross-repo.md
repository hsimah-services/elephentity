# The contract surface

Everything the five repositories share. A change to anything on this list needs issues
on the repositories named beside it; a change to anything else does not.

Read the row for what you touched, not the whole table.

There are **six protocol implementations** across five repositories:
`elephentity` (the compiler/orchestrator client, and the `memory` builder it still
ships since a driver with no physical schema needs no repository of its own),
`elephentity-codegen` (the orchestrator), `elephentity-codegen-php`,
`elephentity-codegen-wordpress` and `elephentity-codegen-wpgraphql`.
`elephentity-codegen-wordpress` and `elephentity-codegen-wpgraphql` used to be
`packages/wordpress/bin/eleph-gen-wordpress` and
`packages/wpgraphql/bin/eleph-gen-wpgraphql` inside `elephentity` itself; the split
(elephentity#62) changed which repository they live in and nothing about the protocol
they speak.

---

## Changing `elephentity`

| You changed | `elephentity-codegen` must | every builder repository must |
|---|---|---|
| `IrCodec::VERSION` (`packages/schema/src/Wire/IrCodec.php`) | bump `Envelope::IR_VERSION` | bump its own `IrCodec::VERSION`/`Envelope::IR_VERSION` copy |
| the shape of `packages/schema/src/Ir/*` | — nothing; the IR is opaque to it | copy the change into its own `src/Ir/*`, re-freeze golden fixtures |
| the compiler request (`GenerateCommand::request()`) | update `Protocol/CompilerRequest` | — |
| what `provides` may contain (`packages/cli/src/Installed.php`) | — nothing; `provides` is opaque to it | answer the new shape to `describe` (only the builder(s) the new shape concerns) |
| a class name or namespace under `packages/runtime/src/` | — | update the matching constant in its own `src/Runtime.php`, if it names that class |
| `eleph.json` keys **it** reads (`spec`, `codegen`) | only if the key is also the orchestrator's | — |

**Every builder repository** currently means `elephentity-codegen-php`,
`elephentity-codegen-wordpress` and `elephentity-codegen-wpgraphql` — the three that
hold a copy of the IR. `packages/memory/bin/eleph-gen-memory` speaks the protocol too
but reads no IR field (a driver with no physical schema needs nothing from `schema`),
so it is unaffected by everything in this table except `Envelope::VERSION` itself.

**The IR is the one that bites.** `packages/schema/src/Ir/*` is *copied* into each
builder's own `src/Ir/*`, not shared. Nothing fails at build time when they diverge:
the compiler encodes a field a builder silently drops, and the generated code — or
compiled manifest — is quietly missing something. Only a version bump makes it loud,
which is why adding a field to the IR is worth bumping for even when an old builder
would technically still run.

**Renaming a runtime class is invisible to every test in every repository.** A builder
emits the name as a string; nothing in that repository loads it. It fails in a
*project*, at boot, after generating. Regenerating `examples/clog` is what catches it —
and it only catches it if the class is one the example actually uses.

---

## Changing `elephentity-codegen`

It owns the protocol, so its blast radius is the largest: **five** other
implementations, not one — `elephentity`'s compiler/client side, the `memory` builder
it also ships, and the three standalone builder repositories.

| You changed | `elephentity` must | every builder repository must |
|---|---|---|
| `Envelope::VERSION` | update its request builder; update `eleph-gen-memory` | bump `Envelope::VERSION` |
| `Envelope::IR_VERSION` | keep `IrCodec::VERSION` equal | bump to match |
| the `describe` request or response | update `Installed::fromJson()`; update `eleph-gen-memory` | update its describe answer |
| the `generate` request or response | update `eleph-gen-memory` | update its generate answer, re-freeze golden fixtures |
| `Protocol/CompilerRequest` | update `GenerateCommand::request()` | — |
| the `targets` command's JSON | update `CheckCommand::outputDirectory()` | — |
| the `describe` command's JSON | update `Installed::fromJson()` | — |
| `Signing/HeaderStyle` — the rendered header or its line count | regenerate **every** tree and commit it | re-freeze golden fixtures |
| `Config/ProjectConfig` — which `eleph.json` keys are required | update the docs and `examples/clog/eleph.json` | — |

**A header change invalidates every signature ever written.** Not just here: every
generated file in every project using Elephentity fails verification at once, because the
digest covers the body and the header is excluded *by line count*. It is the most
expensive change available in this system. Say so in the issue title.

---

## Changing `elephentity-codegen-php`, `elephentity-codegen-wordpress` or `elephentity-codegen-wpgraphql`

The three builder repositories reach the rest of the ecosystem the same way; read the
row for the one you changed.

| You changed | `elephentity` must | `elephentity-codegen` must |
|---|---|---|
| the bytes of any generated file | regenerate `examples/clog` and commit the diff | — |
| the shape of `class-map.php` (php builder only) | update `packages/cli/src/ClassMap.php` | — |
| what it answers to `describe` | update `Installed` and whatever consumes the relevant part of `provides` | — |
| `Envelope::IR_VERSION` or its `src/Ir/*` | keep `IrCodec::VERSION` and `packages/schema/src/Ir/*` in step | bump `Envelope::IR_VERSION` |
| its `src/Runtime.php` constants | keep the matching runtime package (`packages/runtime`, `packages/wordpress` or `packages/wpgraphql`) in step | — |
| which `eleph.json` target keys it needs (`PhpConfig`, or the target's own config reader) | update `examples/clog/eleph.json` and the docs | — |
| a package pattern it ships (wordpress builder: `resources/patterns/*.yml`) | none, if the pattern's shape is unchanged; regenerate if it is | — |

**Generated-byte changes always reach `elephentity`,** even the cosmetic ones. Its
committed `examples/clog/generated/` tree is signed, so a whitespace change is a digest
change is a failing `generate --check` on the next `composer update`. There is no such
thing as a change in a builder that the example does not notice.

---

## What is *not* on the surface

Listed because guessing wrong in this direction is the expensive one.

- **The IR passes through `elephentity-codegen` opaque.** Adding a field to an entity
  needs a new compiler and a new builder, and no release of the orchestrator.
- **`provides` passes through it opaque too**, in the other direction. A builder that
  starts providing a new kind of thing needs a new compiler, not a new orchestrator.
- **A target's `config` block** is read only by the builder that owns it. Adding a key to
  the `php` target reaches nothing but `elephentity-codegen-php`.
- **Anything a builder does internally.** How a builder decides a name, a table shape or
  a manifest's internal structure is its own business right up until the bytes change —
  at which point it is the first row of the builder table above, and it is the bytes
  that are the contract, not the decision.
- **One builder's `src/Runtime.php`, `src/Ir/*` or `src/Protocol/*` copy is invisible to
  every other builder.** Each is independent; `elephentity-codegen-wordpress` falling
  behind the IR does not reach `elephentity-codegen-wpgraphql` or vice versa.
