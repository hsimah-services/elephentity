#!/bin/sh
# Install the `eleph` command line without PHP, Composer or cargo.
#
#   curl -fsSL https://github.com/hsimah-services/elephentity/releases/latest/download/install.sh | sh
#
# Downloads the release archive for this machine, checks it against the release's SHA256SUMS, and
# puts `eleph` in ~/.local/bin. Then `eleph install` fetches the generator and the builders a
# project pins.
#
#   ELEPH_VERSION       the release to install (1.2.3 or v1.2.3); the latest when unset. A first
#                       argument does the same.
#   ELEPH_INSTALL_DIR   where to put it (default ~/.local/bin)
#   ELEPH_RELEASES_URL  where releases are downloaded from (default https://github.com); a
#                       file:// directory laid out the same way works as a mirror.
#
# Linux (x64, arm64) and macOS (arm64). On Windows use install.ps1.

set -eu

repository="hsimah-services/elephentity"
base="${ELEPH_RELEASES_URL:-https://github.com}"
base="${base%/}"
version="${1:-${ELEPH_VERSION:-}}"
version="${version#v}"
directory="${ELEPH_INSTALL_DIR:-$HOME/.local/bin}"

fail() {
    printf 'install.sh: %s\n' "$*" >&2
    exit 1
}

command -v curl >/dev/null 2>&1 || fail "curl is required."

case "$(uname -s)-$(uname -m)" in
    Linux-x86_64) rid=linux-x64 ;;
    Linux-aarch64 | Linux-arm64) rid=linux-arm64 ;;
    Darwin-arm64) rid=osx-arm64 ;;
    *) fail "there is no prebuilt eleph for $(uname -s) on $(uname -m). Releases are built for linux-x64, linux-arm64, osx-arm64 and win-x64 (install.ps1)." ;;
esac

if [ -z "$version" ]; then
    # /releases/latest redirects to /releases/tag/v<version>; the redirect is the answer, and
    # needs no API token and has no rate limit.
    case "$base" in
        file://*) fail "set ELEPH_VERSION: a file:// mirror has no \"latest\"." ;;
    esac

    latest=$(curl -fsSLI -o /dev/null -w '%{url_effective}' "$base/$repository/releases/latest") ||
        fail "could not find the latest release of $repository."
    version="${latest##*/}"
    version="${version#v}"
    [ -n "$version" ] || fail "could not read the latest version from $latest."
fi

archive="eleph-$version-$rid.tar.gz"
release="$base/$repository/releases/download/v$version"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT INT TERM

printf 'Installing eleph %s (%s)\n' "$version" "$rid"

curl -fsSL -o "$work/$archive" "$release/$archive" ||
    fail "could not download $release/$archive. Is there a release v$version for $rid?"
curl -fsSL -o "$work/SHA256SUMS" "$release/SHA256SUMS" ||
    fail "could not download $release/SHA256SUMS."

expected=$(awk -v name="$archive" '{ file = $2; sub(/^\*/, "", file) } file == name { print $1 }' "$work/SHA256SUMS")
[ -n "$expected" ] || fail "SHA256SUMS has no line for $archive."

if command -v sha256sum >/dev/null 2>&1; then
    actual=$(sha256sum "$work/$archive" | awk '{ print $1 }')
elif command -v shasum >/dev/null 2>&1; then
    actual=$(shasum -a 256 "$work/$archive" | awk '{ print $1 }')
else
    fail "sha256sum or shasum is required to verify the download."
fi

[ "$expected" = "$actual" ] ||
    fail "$archive does not match SHA256SUMS (expected $expected, got $actual). Nothing was installed."

tar -xzf "$work/$archive" -C "$work" eleph ||
    fail "$archive has no eleph in it."

mkdir -p "$directory"
# Copy then rename, so an interrupted install never leaves half a binary on the PATH.
cp "$work/eleph" "$directory/.eleph.partial"
chmod 755 "$directory/.eleph.partial"
mv -f "$directory/.eleph.partial" "$directory/eleph"

printf 'Installed %s/eleph\n' "$directory"

case ":$PATH:" in
    *":$directory:"*) ;;
    *) printf 'Add it to your PATH:  export PATH="%s:$PATH"\n' "$directory" ;;
esac
