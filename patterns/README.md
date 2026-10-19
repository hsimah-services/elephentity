# Elephentity patterns

A source catalog of reusable Elephentity patterns. Copy selected patterns into
your project and take ownership of them. This repository is not a Composer
package, does not need Packagist, and is not a production dependency.

## Find and copy

Python 3.10+ is needed only for the optional development helper:

```sh
python3 tools/catalog.py search timestamps
python3 tools/catalog.py copy HasTimestamps --into /path/to/application
```

The helper copies only the chosen pattern, its bundled custom code, and its
declared dependencies. It refuses existing destination files. It writes `.eleph-patterns.json` with source
paths, revision information when available, and SHA-256 hashes. Nothing is
executed from a pattern, and no dependencies are downloaded or installed.

Then add the pattern to your entity's `use` list and run Elephentity's format,
validate, and generation checks. Adjust your application-owned pattern before
generation if its choices do not match your domain.

Manual copying is equally supported: copy `patterns/HasTimestamps/pattern.yml`
to your spec root's `patterns/HasTimestamps.yml`, and copy its PHP trigger using
the destinations in `catalog.json`. Follow the pattern README to wire its handler.
Keep its README and the MIT license with your copy. The helper assumes `spec/`
is your spec root; use
manual copying for a different layout for now.

## Catalog contents

| Pattern | Status | Behavior |
| --- | --- | --- |
| [HasTimestamps](patterns/HasTimestamps/README.md) | Ready | Creation and modification timestamps with bundled PHP trigger |
| [HasSlug](patterns/HasSlug/README.md) | Ready | Required, immutable application-supplied slug |
| HasCreator | Planned | Required creator relationship to a configurable entity; cannot be cleared |

`catalog.json` is the machine-readable search index. `ready` means usable with the
listed schema version; `planned` entries describe upcoming features and are not
installable. `contract: null` in the index means no custom behavior contract is
needed. It is catalog metadata, not YAML compiler syntax.

The accepted scope for `abstract`, `extendable`, and `shared` contracts, entity
parameters, and HasCreator lives in [docs/CONTRACTS.md](docs/CONTRACTS.md).

## Ownership and updates

Installed files are yours to edit. Upstream never overwrites them. For an update,
compare the recorded hashes and source revision with the new source, then merge
the changes deliberately. There is no automatic update command yet. Runtime
dependencies and pattern dependencies must be declared separately in the index.

Agents should search the index, read the selected pattern's README, inspect the
source, and check compatibility before copying. A similar name is not proof of
the required behavior. Do not copy the whole catalog into a production project.

## Development

```sh
python3 tools/catalog.py check
python3 -m unittest discover -s tests -v
# With `eleph` on PATH (install.sh, or set ELEPH) and an Elephentity workspace whose
# elephentity-codegen and elephentity-codegen-php are built, and elephentity-runtime installed:
php tests/compile.php /path/to/elephentity-dev
php tests/timestamps.php /path/to/elephentity-dev
```

The compilation test runs `eleph validate` over every ready pattern; the
Python tests cover selection, provenance, dependency resolution, and collision
handling. The timestamp integration test runs `eleph generate` for two consuming
entities, then tests the bundled trigger through their generated dispatchers.
No Composer manifest is needed in this repository.
