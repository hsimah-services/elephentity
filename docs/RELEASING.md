# Releasing

Three kinds of thing are released from this repository, each by its own tag: prebuilt binaries
(below), the .NET packages (`dotnet-v<version>`), and the PHP packages ([PHP packages](#php-packages)).
One annotated tag per command: GitHub starts no workflows when more than three tags are pushed at
once.

## Prebuilt binaries

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

## PHP packages

Packagist publishes one package per repository, so every Composer package is mirrored, read-only,
to its own repository by [`split.yml`](../.github/workflows/split.yml), and a package's version is
just a tag on its mirror. Packagist has no idea that `runtime`, `sqlite` and `graphql` belong
together, so that has to be enforced here.

**The PHP package family is released together.** `runtime`, `memory`, `sqlite` and `graphql` share
one version. A `php-v<version>` tag syncs all four mirrors and tags each `v<version>`. This is how
the .NET packages already work (`dotnet-v<version>`, seven packages, project references), and for
the same reason: a number that means "one tested set" instead of four versions to keep compatible
by hand. Unchanged members are released anyway.

```bash
git tag -a php-v0.17.0 <commit> -m "notes"   # annotated
git push origin php-v0.17.0
```

**What a version means** (pre-1.0, so the minor carries the weight):

- a **patch** (`0.17.1`) breaks nothing, including a release where only one member changed;
- a **minor** (`0.18.0`) means something in the family breaks: a runtime interface, behaviour a
  caller relies on, or a class name that generated code refers to by string.

Members require each other at the same minor (`"elephentity/runtime": "^0.17"`). A minor bump is
therefore a deliberate event, not a side effect of releasing the compiler.

**Builders are not part of the family.** `codegen`, `codegen-php`, `codegen-sqlite`,
`codegen-graphql-php` and `codegen-tsquid` each have their own line and their own tag,
`<package>-v<version>`, because they are programs with a wire protocol, not libraries. A builder
that depends on the runtime caps it at the minor it was tested with, and is re-released when the
family takes a new minor.

**`eleph` is not part of the family either.** A plain `v<version>` tag releases the binary and
nothing on Packagist. (Until `v0.16.1` it also tagged the `runtime` and `memory` mirrors, which
published runtime versions with no change in them and broke every package that capped the runtime.
The empty versions 0.15.0, 0.16.0 and 0.16.1 are still on Packagist; the family starts at 0.17.0.)

**In development.** `extra.branch-alias.dev-main` in `packages/runtime/composer.json` is the
version the family is heading for. `tools/family-version` reads it, and CI resolves each package's
siblings by path using it (`tests-sqlite.yml`, `tests-graphql.yml`, `tools/test-package-install.sh`),
so a runtime change is tested against the adaptors in the same pull request, before anything is
tagged. After releasing a minor, bump the alias to the next one.

**Releasing a breaking family minor, in order:**

1. A pull request raises the alias and every constraint between members to the new minor, and lets
   the builders accept both lines. CI proves the family against itself by path.
2. Tag `php-v<new>`.
3. A second pull request moves the builders' locks and test pins onto the published family.
4. Tag the builders that changed.
