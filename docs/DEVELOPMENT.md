# Development

PheFr's toolchain is entirely build-time — PHPStan, PHPUnit, PHP-CS-Fixer and the
`phefr` CLI. Nothing runs as a service, so you never need a container sitting in the
background.

## Containerised PHP (recommended)

Install Docker once:

```bash
sudo dnf install moby-engine
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"     # log out and back in for this to take effect
```

Then run any command through the wrapper:

```bash
./tools/php composer install
./tools/php composer ci                  # style, static analysis, architecture, tests
./tools/php vendor/bin/phpunit
./tools/php vendor/bin/phpstan analyse
./tools/php bash                         # interactive shell in the container
```

The image builds itself on first use, and each command runs in a container removed on
exit — nothing accumulates between commands.

Docker does keep a daemon running. When you are finished with PHP for the day:

```bash
sudo systemctl stop docker
```

To reclaim the space entirely:

```bash
docker rmi phefr-dev:8.3
rm -rf ~/.cache/phefr
```

### Fedora specifics the wrapper handles for you

- **SELinux.** Mounts are passed `:z` to relabel them. Without it the container cannot
  read your files at all.
- **File ownership.** The container runs as your UID and GID, so Composer's output
  stays owned by you rather than root.
- **Composer cache.** A Docker *named* volume would be created root-owned and
  unwritable by a `--user` container, so the cache is a host directory at
  `~/.cache/phefr/composer` that the wrapper creates with the right ownership.
- **`HOME`.** An arbitrary UID has no `/etc/passwd` entry and therefore no `HOME`,
  which some tools trip over, so the wrapper sets it explicitly.

Podman is still supported as a fallback if Docker is unavailable — the wrapper detects
whichever is installed, preferring Docker.

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
