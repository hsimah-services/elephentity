# Where rust/ir.rs comes from

`rust/ir.rs` is a **byte-identical copy** of `rust/ir.rs` in
[elephentity-codegen-php](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen-php) at
`44c4d0cb64ac6c736a8ffe81e3844c60e8659470`: wire IR 1.2, with the typed decoder every Rust builder
carries. Each builder owns its own copy so that none of them depends on the compiler or on another
builder; the version gate is what keeps the copies honest, not a shared crate.

| | |
|---|---|
| source | `elephentity-codegen-php/rust/ir.rs` |
| commit | `44c4d0cb64ac6c736a8ffe81e3844c60e8659470` |
| sha256 | `eb18549bdd06399c040910c1cb3edbec7c7fba1c2a6dfe02731b2d5332eac881` |

`tests/ir_copy.rs` fails if this file's hash and `rust/ir.rs` disagree, so the copy cannot drift by
accident. To update it, copy the new file over, record the new commit and hash here, and say why in the
commit: an IR version bump is a change to every builder, merged together
(`.llms/cross-repo.md`).

```bash
sha256sum rust/ir.rs
curl -fsSL https://raw.githubusercontent.com/hsimah-services/elephentity-codegen-php/44c4d0cb64ac6c736a8ffe81e3844c60e8659470/rust/ir.rs | sha256sum
```
