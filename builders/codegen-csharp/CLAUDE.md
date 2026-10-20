# elephentity-codegen-csharp

The C# builder for Elephentity: the IR in on stdin, C# source out on stdout.

Read [README.md](README.md), then `docs/PROTOCOL.md` in
[elephentity-codegen](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen): that is the contract
this implements, and this file assumes it.

## Working in this repository

There is no host cargo. Run it in the Rust container from the `elephentity` repository
(`./tools/cargo cargo test --locked --manifest-path ...`), or any equivalent container.

```bash
cargo fmt --check
cargo clippy --locked --all-targets -- -D warnings
cargo test --locked
```

## Three rules, and they are the whole of it

1. **Read one JSON object from stdin. Write one JSON object to stdout. Exit 0.**
2. **Never touch the filesystem.** Return paths and bodies. Signing happens after this exits.
3. **Diagnostics go to stderr.** stdout is the response and nothing else. Malformed input is a
   non-zero exit; bad configuration is a normal response with `errors` and no files.

## Conventions that are load bearing

- **Errors accumulate.** `config_problems` reports every bad key at once.
- **`rust/ir.rs` is not edited here.** It is a byte-identical copy (SOURCE.md); `tests/ir_copy.rs` fails
  on drift. An IR change reaches every builder and merges together with the compiler and orchestrator.
- **Names that cross a repository boundary** (the `Elephentity.Runtime` type names the emitters write)
  are strings in `rust/runtime.rs`. Renaming a class in `elephentity-dotnet` fails nothing in `cargo test`;
  the `generated` CI job (`tests/dotnet`) compiles every golden tree against the pinned runtime and catches it.
- **Generated code uses no reflection** and nothing that stops Native AOT.

## Before you commit anything that crosses a repository boundary

[`.llms/cross-repo.md`](.llms/cross-repo.md) is the closed list of what crosses; if you changed
something on it, open an issue on each repository it reaches (`.llms/README.md`, `.llms/issue-template.md`).
The four `.llms/` files are identical in every repository that carries them.
