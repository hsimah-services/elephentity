# elephentity/cli

Install with `composer require --dev elephentity/cli`.

This repository mirrors `packages/cli` in [elephentity](https://github.com/hsimah-services/elephentity). Submit source, test and packaging changes there. The mirror is synchronized on main pushes and released on the source tag line.

See the [installation guide](https://github.com/hsimah-services/elephentity/blob/main/docs/GETTING_STARTED.md) and [package migration guide](https://github.com/hsimah-services/elephentity/blob/main/docs/PACKAGES.md).

`eleph validate spec` validates without generating artifacts, using live builder
metadata from `eleph.json`. For a spec review without builder executables or a
project config, use `eleph validate spec --provides provides.json` with a saved
`eleph-codegen describe` response. This checks against supplied metadata, not
installed-builder compatibility. See the [validation guide](https://github.com/hsimah-services/elephentity/blob/main/docs/CI.md).
