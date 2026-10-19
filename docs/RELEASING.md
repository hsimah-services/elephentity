# Releasing prebuilt binaries

`eleph` and every Rust builder ship as prebuilt binaries on GitHub Releases, so a project does
not need Composer or cargo to use them. This repository hosts the one workflow they all reuse:
[`release-binary.yml`](../.github/workflows/release-binary.yml), called from `release.yml`.
The .NET packages (NuGet, `release-dotnet.yml`) and the Composer mirrors (`split.yml`) are
released by their own tags; see the table in [`CLAUDE.md`](../CLAUDE.md).

## The artifact naming contract

`eleph install` (step 8 of hsimah-services/elephentity#115) finds, downloads and verifies
binaries by these names, so they are a contract. Changing any of it is a change to
[`.llms/cross-repo.md`](../.llms/cross-repo.md).

**Tag.** Annotated. One repository releases many programs, each on its own version, so the tag
names the program: `v<version>` for `eleph`, `<binary>-v<version>` for any other (for example
`eleph-gen-csharp-v0.1.0`). The version is what follows the `v`. A *version* containing a hyphen
(`0.2.0-alpha.1`) is a pre-release; the hyphens in the binary name do not count.

**Archives.** One per platform, named `<binary>-<version>-<rid>.<ext>`:

| rid | Rust target | Archive | Built on |
|---|---|---|---|
| `linux-x64` | `x86_64-unknown-linux-gnu` | `.tar.gz` | ubuntu-22.04 |
| `linux-arm64` | `aarch64-unknown-linux-gnu` | `.tar.gz` | ubuntu-22.04-arm |
| `osx-arm64` | `aarch64-apple-darwin` | `.tar.gz` | macos-14 |
| `win-x64` | `x86_64-pc-windows-msvc` | `.zip` | windows-2022 |

For example `eleph-codegen-0.2.0-linux-x64.tar.gz`. Each archive holds exactly two entries
at its top level: `<binary>` (`<binary>.exe` on Windows) and `LICENSE`.

The Linux builds use the oldest runner images on purpose. A binary needs at least the glibc
it was linked against, and Raspberry Pi OS (Debian 12) ships 2.36, so the Linux archives
need glibc 2.35 or newer.

**Checksums.** One `SHA256SUMS` asset covers every archive: `sha256sum` format, a hex
digest, two spaces, then the bare archive name, one line per archive. `sha256sum -c
SHA256SUMS` passes when run next to the archives.

**Release.** Named by its tag. The notes are the annotated tag's message. It holds the four
archives and `SHA256SUMS`, nothing else.

## What the workflow checks

On every run, tag or not, before anything is published:

1. All four archives build with `cargo build --release --locked`, so `Cargo.lock` must be
   committed.
2. There are exactly four archives, one per rid, and `sha256sum -c SHA256SUMS` passes.
3. The linux-x64 binary starts, answering `--help`, in clean `debian:stable-slim` and
   `debian:bookworm-slim` containers. "Starts" means it is not a missing file, a missing
   library or a crash; whatever else it says is its own business.

Publishing is the last job and runs only for a tag. Called from a branch or a pull request
the workflow stops after the checks, which is how a change to it is tested without pushing
a tag. `release-check.yml` does that for this repository whenever the workflow, the
manifest or the Rust source change.

## Cutting a release

Releasing is the owner's call. Tag the commit and push the tag:

```bash
git tag -a eleph-codegen-v0.2.0 -m "Release notes go here: this message becomes the GitHub Release body."
git push origin eleph-codegen-v0.2.0
```

`release.yml` runs on the tag, builds, verifies and creates the Release. Pushing the tag
again after a fix updates the existing Release in place, replacing its assets.

## Adding a binary

1. Add its crate under `builders/` (a committed `Cargo.lock`; the repository's `LICENSE` is
   packed into the archive).
2. In `release.yml`, add its tag pattern (`<binary>-v*`) to `on.push.tags` and a job that calls
   `release-binary.yml` with `binary:` and `manifest-path:`.
3. Copy a `release-check-<binary>.yml` so a change to the release path is tested on a pull
   request.
4. Add it to `KNOWN` in `compiler/src/cli/install.rs` so a project can pin it.
