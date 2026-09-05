# Development

PheFr's toolchain is entirely build-time — PHPStan, PHPUnit, PHP-CS-Fixer and the
`phefr` CLI. Nothing runs as a service, so you never need a container sitting in the
background.

## Containerised PHP (recommended)

Install podman once:

```bash
sudo dnf install podman
```

Then run any command through the wrapper:

```bash
./tools/php composer install
./tools/php composer ci                  # style, static analysis, architecture, tests
./tools/php vendor/bin/phpunit
./tools/php vendor/bin/phpstan analyse
./tools/php bash                         # interactive shell in the container
```

The image builds itself on first use and each command runs in a container removed on
exit. **There is nothing to turn off** — podman has no daemon, so when you stop running
commands, nothing is left running at all.

To reclaim the space when you are done with PHP for a while:

```bash
podman rmi phefr-dev:8.3
rm -rf ~/.cache/phefr
```

### Why podman here

- **No daemon.** Docker leaves `dockerd` running and needs `sudo systemctl stop docker`
  to actually stop; podman has nothing to stop.
- **Smaller.** 49 MiB installed against 108 MiB for `moby-engine`. (Both are dwarfed by
  the `php:8.3-cli` image itself, so treat this as a tiebreaker rather than the reason.)
- **Rootless.** Podman maps your host user into the container, so files created by
  Composer come out owned by you without any extra flags.

Docker is supported as an automatic fallback. The wrapper detects whichever is
installed and passes `--user` under docker only, since podman already maps the user and
doing both would double-map it.

### Fedora specifics the wrapper handles for you

- **SELinux.** Mounts are passed `:z` to relabel them. Without it the container cannot
  read your files at all.
- **Composer cache.** Kept at `~/.cache/phefr/composer` as a host directory rather than
  a named volume — under docker's `--user` a named volume is created root-owned and
  unwritable, and a host directory works identically for podman.
- **`HOME`.** Set explicitly, because a UID with no `/etc/passwd` entry has none and
  some tools trip over that.

## Local PHP instead

If you would rather not use containers:

```bash
sudo dnf install php-cli php-mbstring php-xml php-zip composer
composer install
composer ci
```

PHP 8.3 is the floor. Fedora 44 ships a newer PHP than that, which is fine — CI tests
8.3 and 8.4, so anything in that range is representative.

## What CI runs

`.github/workflows/ci.yml` runs the same four gates as `composer ci`, over a PHP 8.3
and 8.4 matrix:

| Gate | Command |
|---|---|
| Code style | `php-cs-fixer fix --dry-run --diff` |
| Static analysis | `phpstan analyse` (level max) |
| Architecture rules | `composer arch` |
| Tests | `phpunit` |

The PheFr-specific gates — `validate`, `generate --check`, signature verification,
`fmt --check` and conformance — are added as their packages land. See §14 of
[PLAN.md](PLAN.md).

## Repository layout

```
docs/                  PLAN.md — every design decision and its reasoning
packages/
  schema/              spec parser, JSON Schema, pattern resolution, IR   (dev-only)
  codegen/             IR → locked, signed PHP                            (dev-only)
  runtime/             storage port, unit of work, verification, loaders  (shipped)
  wordpress/           the one adaptor; only package that may name WP_*   (shipped)
  wpgraphql/           IR → compiled registration manifest                (shipped)
  cli/                 the phefr command                                  (dev-only)
tools/
  php                  run a command in the throwaway PHP container
  check-architecture.php  enforces the platform-free core
```
