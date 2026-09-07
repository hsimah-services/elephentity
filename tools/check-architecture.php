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
 * Uses the tokenizer rather than grep, so prose in a doc comment discussing WordPress
 * is not mistaken for a dependency on it — and neither is a string literal.
 *
 * The code generator used to be checked here too. It lives in its own repository now
 * and has no WordPress stubs to reach for even by accident, so the rule went with it.
 */

const CORE_PACKAGES = ['schema', 'runtime', 'cli'];

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

printf(
    "Architecture rules: OK (%d core packages are platform-free)\n",
    count(CORE_PACKAGES),
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
