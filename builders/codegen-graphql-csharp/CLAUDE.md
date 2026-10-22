# elephentity-codegen-graphql-csharp

The GraphQL builder for the .NET stack: the IR in on stdin, the C# GraphQL manifest out on stdout.

Read [README.md](README.md), then `docs/PROTOCOL.md` in
[elephentity-codegen](https://github.com/hsimah-services/elephentity/tree/main/builders/codegen): that is the contract
this implements, and this file assumes it.

## Working in this directory

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

- **Errors accumulate.** `config_problems` and `validation::validate` is the PHP builder's, rule for rule.
- **`rust/ir.rs` is not edited here.** It is a byte-identical copy (SOURCE.md); `tests/ir_copy.rs` fails
  on drift. An IR change reaches every builder and merges together with the compiler and orchestrator.
- **Names that cross a repository boundary** (the `Elephentity.GraphQL` manifest types the emitter names)
  are strings in `rust/manifest.rs`. Renaming a class in `elephentity-dotnet` fails nothing in `cargo test`;
  the `generated` CI job (`tests/dotnet`) compiles every manifest against the generated entities and the pinned `Elephentity.GraphQL`, and compares its SDL with the PHP stack's

## Before you commit anything that crosses a program boundary

[`.llms/cross-repo.md`](../../.llms/cross-repo.md) is the closed list of what crosses; if you changed
something on it, open an issue on each repository it reaches (`../../.llms/README.md`, `../../.llms/issue-template.md`).
There is one copy, at the root of the monorepo; do not copy it into a program's directory.
