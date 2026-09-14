# The contract surface

Everything the nine repositories share. A change to anything on this list needs issues
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

The other four repositories speak no protocol themselves, but are still on this
surface: **`elephentity-runtime`** is a read-only mirror of `elephentity`'s
`packages/runtime`, so anything that reaches `packages/runtime` reaches it for free on
the next split — it is never a place to file an issue *against*.
**`elephentity-wordpress`** and **`elephentity-wpgraphql`** are runtime packages
(elephentity#79) that the two matching builders name classes from, as strings, in their
own `src/Runtime.php` — the coupling a version gate cannot catch, covered below.
**`elephentity-examples`** consumes all of it as a real product; regenerating `clog`
there is the end-to-end check that a class rename or a drifted answer to `describe`
actually surfaces somewhere, since nothing type-checks across any of these gaps.

---

## Changing `elephentity`

| You changed | `elephentity-codegen` must | every builder repository must |
|---|---|---|
| `IrCodec::VERSION` (`packages/schema/src/Wire/IrCodec.php`) | bump `Envelope::IR_VERSION` | bump its own `IrCodec::VERSION`/`Envelope::IR_VERSION` copy |
| the shape of `packages/schema/src/Ir/*` | — nothing; the IR is opaque to it | copy the change into its own `src/Ir/*`, re-freeze golden fixtures |
| the compiler request (`GenerateCommand::request()`) | update `Protocol/CompilerRequest` | — |
| what `provides` may contain (`packages/cli/src/Installed.php`) | — nothing; `provides` is opaque to it | answer the new shape to `describe` (only the builder(s) the new shape concerns) |
| a class name or namespace under `packages/runtime/src/` | — | update the matching constant in its own `src/Runtime.php`, if it names that class |
| an interface under `packages/runtime/src/` that `elephentity-wordpress` or `elephentity-wpgraphql` implements (`StorageAdaptor`, `Verifier`, `Viewer`, …) | — | `elephentity-wordpress`/`elephentity-wpgraphql` must update the implementing class to match |
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
*project*, at boot, after generating. Regenerating `clog` in `elephentity-examples` is
what catches it — and it only catches it if the class is one the example actually uses.

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
| `Config/ProjectConfig` — which `eleph.json` keys are required | update the docs and `elephentity-examples`'s `clog/eleph.json` | — |

**A header change invalidates every signature ever written.** Not just here: every
generated file in every project using Elephentity fails verification at once, because the
digest covers the body and the header is excluded *by line count*. It is the most
expensive change available in this system. Say so in the issue title.

---

## Changing `elephentity-codegen-php`, `elephentity-codegen-wordpress` or `elephentity-codegen-wpgraphql`

The three builder repositories reach the rest of the ecosystem the same way; read the
row for the one you changed.

| You changed | `elephentity-examples` must | `elephentity-codegen` must |
|---|---|---|
| the bytes of any generated file | regenerate `clog` and commit the diff | — |
| the shape of `class-map.php` (php builder only) | — (`packages/cli/src/ClassMap.php` lives in `elephentity`; open an issue there too) | — |
| what it answers to `describe` | — (`Installed` lives in `elephentity`; open an issue there too) | — |
| `Envelope::IR_VERSION` or its `src/Ir/*` | keep `IrCodec::VERSION` and `elephentity`'s `packages/schema/src/Ir/*` in step | bump `Envelope::IR_VERSION` |
| its `src/Runtime.php` constants | regenerate `clog` — a stale constant fails there, at boot | — (`elephentity-wordpress`/`elephentity-wpgraphql` own the classes named; keep them in step, or file there too) |
| which `eleph.json` target keys it needs (`PhpConfig`, or the target's own config reader) | update `clog/eleph.json` and the docs | — |
| a package pattern it ships (wordpress builder: `resources/patterns/*.yml`) | none, if the pattern's shape is unchanged; regenerate if it is | — |

**Generated-byte changes always reach `elephentity-examples`,** even the cosmetic ones.
Its committed `clog/generated/` tree is signed, so a whitespace change is a digest
change is a failing `generate --check` on the next `composer update`. There is no such
thing as a change in a builder that the example does not notice.

---

## Changing `elephentity-wordpress` or `elephentity-wpgraphql`

The two runtime adaptor repositories (elephentity#79). Neither speaks the wire protocol
itself — a builder names their classes as strings, not imports — so nothing here bumps
`Envelope::VERSION` or `IrCodec::VERSION`.

| You changed | the matching builder repository must | `elephentity-examples` must |
|---|---|---|
| a class name or namespace this package exports (`elephentity-wordpress`: anything under `Eleph\WordPress\`; `elephentity-wpgraphql`: `Eleph\WPGraphQL\`) | update the matching constant in its own `src/Runtime.php` — nothing fails there until a project boots on the old name | regenerate `clog` and confirm it still boots; this is the check that actually catches a missed update |
| a class implementing `Eleph\Runtime\Storage\StorageAdaptor`, `Conformance\Verifier`, `Policy\Viewer` or `Policy\ViewerProvider` | — | run the four gates; `eleph check` exercises the verifier for real |
| its own `composer.json` version constraint on `elephentity/runtime` | — | bump the matching constraint in `clog/composer.json` once a new version is tagged |

**A rename here is invisible to every test in this repository, in the matching builder
repository, and in `elephentity`.** It fails in a real project, at boot, when the
exported manifest or verifier names a class that no longer exists. Regenerating `clog`
in `elephentity-examples` is the only thing that actually runs that path.

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
