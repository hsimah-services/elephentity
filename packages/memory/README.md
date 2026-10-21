> **This is the source of truth.** `hsimah-services/elephentity-memory` is a read-only mirror of [`packages/memory/`](https://github.com/hsimah-services/elephentity/tree/main/packages/memory) in [`hsimah-services/elephentity`](https://github.com/hsimah-services/elephentity), kept for Composer and Packagist. Open issues and pull requests on `elephentity`.

# elephentity/memory

Install with `composer require elephentity/memory`.

The neutral storage driver: raw PHP objects, no platform, no physical schema. It also ships
`vendor/bin/eleph-gen-memory`, the builder that declares the `memory` driver to `eleph`. Name it
as a target in `eleph.json`:

```json
"memory": {"builder": "vendor/bin/eleph-gen-memory", "output": "generated/memory"}
```

This repository mirrors `packages/memory` in [elephentity](https://github.com/hsimah-services/elephentity). Submit source, test and packaging changes there. The mirror is synchronized on main pushes and released on the source tag line.

See the [installation guide](https://github.com/hsimah-services/elephentity/blob/main/docs/GETTING_STARTED.md) and [package migration guide](https://github.com/hsimah-services/elephentity/blob/main/docs/PACKAGES.md).
