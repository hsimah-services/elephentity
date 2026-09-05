# Development

PheFr's toolchain is entirely build-time — PHPStan, PHPUnit, PHP-CS-Fixer and the
`phefr` CLI. Nothing runs as a service, so you never need a container sitting in the
background.

## Containerised PHP (recommended)

Install a container engine once:

```bash
sudo dnf install podman        # Fedora's native choice: rootless and daemonless
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
exit. **There is nothing to turn off.** Podman has no daemon either, so when you stop
running commands, nothing is left running at all.

To reclaim the space when you are done with PHP for a while:

```bash
podman rmi phefr-dev:8.3
podman volume rm phefr-composer-cache
```

### Why podman over docker here

Rootless podman maps your host user to root inside the container, so files created by
Composer come out owned by you. Docker runs as real root and leaves root-owned files
in your working tree — `tools/php` passes `--user` to compensate, but podman needs no
compensation. Podman also has no background daemon, which is closer to what you asked
for than "a container you turn off".

The wrapper mounts the working tree with `:z` because Fedora enforces SELinux, and
without relabelling the container cannot read your files.

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
| Architecture rules | `./tools/check-architecture.sh` |
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
  check-architecture.sh  enforces the platform-free core
```
