#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Architecture rules that static analysis cannot express yet.
 *
 * Rule 1 — the platform-free core.
 *   packages/schema, runtime and cli must not reference WordPress at all.
 *   Only packages/wordpress and packages/wpgraphql may.
 *
 *   This is what keeps the storage abstraction honest. WordPress concepts leak
 *   quietly, and by the time they are load-bearing a second adaptor is no longer
 *   possible. Enforcing it from day one costs nothing; retrofitting it costs a rewrite.
 *
 * Rule 2 — the schema-free runtime.
 *   The packages that ship — runtime, wordpress, wpgraphql — must not reference
 *   `Eleph\Schema` except from the build-time classes named below.
 *
 *   `elephentity/schema` is dev-only: it parses YAML and exists to produce the IR, and
 *   the plan says dev packages never reach production. A shipped class naming one is
 *   how that stops being true, and it did — a compiled storage manifest contained
 *   `Schema\Ir\RelationKind::OneToMany` as a literal, so loading it pulled the spec
 *   compiler into every request of every WordPress project.
 *
 *   The allowlist is deliberately a list of files rather than a namespace convention.
 *   Each entry is a class that runs at build time and could not run at any other, and
 *   adding one should take a moment's thought rather than a directory choice. When
 *   these move to packages of their own the list empties and the rule stays.
 *
 * Uses the tokenizer rather than grep, so prose in a doc comment discussing WordPress
 * is not mistaken for a dependency on it — and neither is a string literal.
 *
 * The code generator used to be checked here too. It lives in its own repository now
 * and has no WordPress stubs to reach for even by accident, so the rule went with it.
 */

const CORE_PACKAGES = ['schema', 'runtime', 'cli'];

/** The packages that ship, and so may not reach for the spec compiler. */
const SHIPPED_PACKAGES = ['runtime', 'wordpress', 'wpgraphql'];

/**
 * Build-time classes inside a shipped package, allowed to read the IR.
 *
 * Each turns a compiled spec into something the runtime loads later; none of them is
 * reachable from a request. Paths are relative to packages/.
 */
const COMPILERS = [
    'wordpress/bin/eleph-gen-wordpress',
    'wordpress/src/Manifest/PostTypeManifestBuilder.php',
    'wordpress/src/Manifest/StorageManifestBuilder.php',
    'wordpress/src/Sql/EdgePlanner.php',
    'wordpress/src/Sql/SchemaBuilder.php',
    'wpgraphql/bin/eleph-gen-wpgraphql',
    'wpgraphql/src/Integration/WpGraphQL.php',
    'wpgraphql/src/Manifest/ManifestBuilder.php',
    'wpgraphql/src/Manifest/TypeMapper.php',
];

/** Hook and option functions that are WordPress even without a wp_ prefix. */
const WORDPRESS_FUNCTIONS = [
    'add_action', 'add_filter', 'apply_filters', 'do_action',
    'get_option', 'update_option', 'delete_option', 'register_post_type',
];

$root = dirname(__DIR__);
$violations = [];

foreach (CORE_PACKAGES as $package) {
    $directory = $root . '/packages/' . $package;

    if (!is_dir($directory)) {
        continue;
    }

    foreach (phpFilesIn($directory) as $file) {
        foreach (wordpressSymbolsIn($file) as $line => $symbol) {
            $violations[] = sprintf(
                '%s:%d references %s',
                substr($file, strlen($root) + 1),
                $line,
                $symbol,
            );
        }
    }
}

if ([] !== $violations) {
    fwrite(STDERR, "ERROR: the core packages must stay platform-free.\n");
    fwrite(STDERR, "Only packages/wordpress and packages/wpgraphql may reference WordPress.\n\n");

    foreach ($violations as $violation) {
        fwrite(STDERR, '  ' . $violation . "\n");
    }

    exit(1);
}

$leaks = [];

foreach (SHIPPED_PACKAGES as $package) {
    $directory = $root . '/packages/' . $package;

    if (!is_dir($directory)) {
        continue;
    }

    foreach (sourceFilesIn($directory) as $file) {
        $relative = substr($file, strlen($root . '/packages/'));

        if (in_array($relative, COMPILERS, true)) {
            continue;
        }

        foreach (schemaSymbolsIn($file) as $line => $symbol) {
            $leaks[] = sprintf('packages/%s:%d references %s', $relative, $line, $symbol);
        }
    }
}

if ([] !== $leaks) {
    fwrite(STDERR, "ERROR: a shipped package reaches for the spec compiler.\n");
    fwrite(STDERR, "elephentity/schema is dev-only. A class that runs at build time and\n");
    fwrite(STDERR, "genuinely needs the IR goes in COMPILERS, in this file, deliberately.\n\n");

    foreach ($leaks as $leak) {
        fwrite(STDERR, '  ' . $leak . "\n");
    }

    exit(1);
}

printf(
    "Architecture rules: OK (%d core packages are platform-free, %d shipped packages are schema-free)\n",
    count(CORE_PACKAGES),
    count(SHIPPED_PACKAGES),
);

/**
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || 'php' !== $entry->getExtension()) {
            continue;
        }

        if (str_contains($entry->getPathname(), '/vendor/')) {
            continue;
        }

        $files[] = $entry->getPathname();
    }

    sort($files);

    return $files;
}

/**
 * Like phpFilesIn(), plus the extensionless builder entrypoints.
 *
 * A builder is a `bin/` script rather than a `.php` file, and it is exactly the kind of
 * thing that reads the IR — so a rule about reading the IR that skipped it would miss
 * the clearest case.
 *
 * @return list<string>
 */
function sourceFilesIn(string $directory): array
{
    // src/ and bin/ only. A test compiles a fixture spec to have something to assert
    // against, which is the IR being read by something that never ships — the rule is
    // about what a plugin loads, not about what proves it works.
    $files = is_dir($directory . '/src') ? phpFilesIn($directory . '/src') : [];

    foreach (glob($directory . '/bin/*') ?: [] as $entry) {
        if (is_file($entry)) {
            $files[] = $entry;
        }
    }

    sort($files);

    return $files;
}

/**
 * References to the spec compiler, by namespace.
 *
 * The tokenizer again, so a doc comment explaining why a class does *not* read the IR
 * is not read as a class that does.
 *
 * @return array<int, string> line number => offending symbol
 */
function schemaSymbolsIn(string $file): array
{
    $source = file_get_contents($file);

    if (false === $source) {
        return [];
    }

    $found = [];

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;

        if (T_NAME_QUALIFIED === $id || T_NAME_FULLY_QUALIFIED === $id) {
            if (str_starts_with(ltrim($text, '\\'), 'Eleph\\Schema\\')) {
                $found[$line] = $text;
            }
        }
    }

    return $found;
}

/**
 * Identifiers and variables only. Comments, doc blocks and strings are ignored.
 *
 * @return array<int, string> line number => offending symbol
 */
function wordpressSymbolsIn(string $file): array
{
    $source = file_get_contents($file);

    if (false === $source) {
        return [];
    }

    $found = [];

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;

        if (T_STRING === $id && (str_starts_with($text, 'WP_') || str_starts_with($text, 'wp_'))) {
            $found[$line] = $text;

            continue;
        }

        if (T_STRING === $id && in_array($text, WORDPRESS_FUNCTIONS, true)) {
            $found[$line] = $text . '()';

            continue;
        }

        if (T_VARIABLE === $id && '$wpdb' === $text) {
            $found[$line] = '$wpdb';
        }
    }

    return $found;
}
