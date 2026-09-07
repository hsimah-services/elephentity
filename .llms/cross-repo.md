# The contract surface

Everything the three repositories share. A change to anything on this list needs issues
on the repositories named beside it; a change to anything else does not.

Read the row for what you touched, not the whole table.

---

## Changing `elephentity`

| You changed | `elephentity-codegen` must | `elephentity-codegen-php` must |
|---|---|---|
| `IrCodec::VERSION` (`packages/schema/src/Wire/IrCodec.php`) | bump `Envelope::IR_VERSION` | bump `Envelope::IR_VERSION` **and** `src/Wire/IrCodec.php`'s copy |
| the shape of `packages/schema/src/Ir/*` | — nothing; the IR is opaque to it | copy the change into `src/Ir/*`, re-freeze `tests/fixtures/golden/` |
| the compiler request (`GenerateCommand::request()`) | update `Protocol/CompilerRequest` | — |
| what `provides` may contain (`packages/cli/src/Installed.php`) | — nothing; `provides` is opaque to it | answer the new shape to `describe` |
| a class name or namespace under `packages/runtime/src/` | — | update the matching constant in `src/Runtime.php` |
| `eleph.json` keys **it** reads (`spec`, `codegen`) | only if the key is also the orchestrator's | — |

**The IR is the one that bites.** `packages/schema/src/Ir/*` is *copied* into
`elephentity-codegen-php/src/Ir/*`, not shared. Nothing fails here when they diverge:
the compiler encodes a field the builder silently drops, and the generated code is
quietly missing something. Only a version bump makes it loud, which is why adding a
field to the IR is worth bumping for even when the old builder would technically still
run.

**Renaming a runtime class is invisible to every test in every repository.** The builder
emits the name as a string; nothing here loads it. It fails in a *project*, at boot,
after generating. Regenerating `examples/clog` is what catches it — and it only catches
it if the class is one the example actually uses.

---

## Changing `elephentity-codegen`

It owns the protocol, so its blast radius is the largest: **four** implementations, not
one. `elephentity-codegen-php`, and the two builders `elephentity` itself ships —
`packages/wordpress/bin/eleph-gen-wordpress` and
`packages/wpgraphql/bin/eleph-gen-wpgraphql`.

| You changed | `elephentity` must | `elephentity-codegen-php` must |
|---|---|---|
| `Envelope::VERSION` | update its request builder; update **both** shipped builders | bump `Envelope::VERSION` |
| `Envelope::IR_VERSION` | keep `IrCodec::VERSION` equal | bump to match |
| the `describe` request or response | update `Installed::fromJson()`; update both shipped builders | update its describe answer |
| the `generate` request or response | update both shipped builders | update its generate answer, re-freeze golden fixtures |
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

## Changing `elephentity-codegen-php`

| You changed | `elephentity` must | `elephentity-codegen` must |
|---|---|---|
| the bytes of any generated file | regenerate `examples/clog` and commit the diff | — |
| the shape of `class-map.php` | update `packages/cli/src/ClassMap.php` | — |
| what it answers to `describe` | update `Installed` and whatever consumes `provides` | — |
| `Envelope::IR_VERSION` or `src/Ir/*` | keep `IrCodec::VERSION` and `packages/schema/src/Ir/*` in step | bump `Envelope::IR_VERSION` |
| `src/Runtime.php` constants | keep `packages/runtime/src/` in step | — |
| `PhpConfig` — which `eleph.json` target keys it needs | update `examples/clog/eleph.json` and the docs | — |

**Generated-byte changes always reach `elephentity`,** even the cosmetic ones. Its
committed `examples/clog/generated/` tree is signed, so a whitespace change is a digest
change is a failing `generate --check` on the next `composer update`. There is no such
thing as a change here that the example does not notice.

---

## What is *not* on the surface

Listed because guessing wrong in this direction is the expensive one.

- **The IR passes through `elephentity-codegen` opaque.** Adding a field to an entity
  needs a new compiler and a new builder, and no release of the orchestrator.
- **`provides` passes through it opaque too**, in the other direction. A builder that
  starts providing a new kind of thing needs a new compiler, not a new orchestrator.
- **A target's `config` block** is read only by the builder that owns it. Adding a key to
  the `php` target reaches nothing but `elephentity-codegen-php`.
- **Anything a builder does internally.** How `eleph-gen-php` decides a class name is its
  own business right up until the bytes change — at which point it is the first row of
  the table above, and it is the bytes that are the contract, not the decision.
