#!/usr/bin/env bash
#
# Rewrites book-author.graphql: the SDL the PHP package (elephentity-graphql-php, on webonyx) prints for the book/author model,
# normalised the way `tsquid-schema fetch` does it (graphql-js printSchema over lexicographicSortSchema).
#
# Needs podman or docker, the PHP image eleph-dev:8.3 (built by elephentity/tools/php), a checkout of elephentity-graphql-php,
# an installed vendor/ that has webonyx and elephentity/runtime (builders/codegen-graphql-php/tests has one), the node:22-slim
# image, and an installed tsquid checkout for graphql-js. Point GRAPHQL_PHP, VENDOR and TSQUID at them.

set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
engine="$(command -v podman || command -v docker)"
graphql_php="${GRAPHQL_PHP:-$here/../../../../packages/graphql}"
vendor="${VENDOR:-$here/../../../../builders/codegen-graphql-php/tests/vendor}"
tsquid="${TSQUID:-$here/../../../../../tsquid}"

"$engine" run --rm \
    -v "$here:/work:z" -v "$graphql_php/src:/graphql-php:z" -v "$vendor:/vendor:z" \
    eleph-dev:8.3 php /work/book-author.php /vendor/autoload.php /graphql-php > "$here/book-author.introspection.json"

"$engine" run --rm -v "$here:/work:z" -v "$tsquid:/tsquid:z" -e GRAPHQL_FROM=/tsquid/packages/codegen/package.json \
    node:22-slim node /work/normalize.mjs /work/book-author.introspection.json > "$here/book-author.graphql"
