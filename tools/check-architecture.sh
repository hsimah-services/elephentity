#!/usr/bin/env bash
#
# Architecture rules that static analysis cannot express yet.
#
# Rule 1 — the platform-free core.
#   packages/schema, codegen, runtime and cli must not reference WordPress at all.
#   Only packages/wordpress and packages/wpgraphql may.
#
#   This is the rule that keeps the storage abstraction honest. WordPress concepts
#   (int post IDs, postmeta as untyped KV, taxonomies-as-edges, WP_Query semantics)
#   leak quietly, and by the time they are load-bearing a second adaptor is no longer
#   possible. Enforcing it from day one costs nothing; retrofitting it costs a rewrite.
#
# TODO once codegen lands: "Entity never writes" and "Mutator never returns an Entity".
# Those want a PHPStan custom rule, not grep.

set -euo pipefail

cd "$(dirname "$0")/.."

CORE_PACKAGES=(schema codegen runtime cli)

# WP_Foo classes, wp_foo() calls, the hook API, and the global $wpdb.
WORDPRESS_PATTERN='\bWP_[A-Za-z_]|\bwp_[a-z_]+[[:space:]]*\(|\b(add_action|add_filter|apply_filters|do_action|get_option|update_option)[[:space:]]*\(|\$wpdb\b'

status=0

for package in "${CORE_PACKAGES[@]}"; do
    dir="packages/${package}"
    [ -d "$dir" ] || continue

    if matches=$(grep -rnE "$WORDPRESS_PATTERN" \
            --include='*.php' \
            --exclude-dir=vendor \
            "$dir" 2>/dev/null); then
        echo "ERROR: ${dir} references WordPress, but only packages/wordpress and"
        echo "       packages/wpgraphql may. The core must stay platform-free."
        echo "$matches" | sed 's/^/         /'
        echo
        status=1
    fi
done

if [ "$status" -eq 0 ]; then
    echo "Architecture rules: OK (${#CORE_PACKAGES[@]} core packages are platform-free)"
fi

exit "$status"
