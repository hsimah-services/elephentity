#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Architecture rules that static analysis cannot express yet.
 *
 * Rule 1 — the platform-free core.
 *   No package in this repository may reference WordPress at all. The WordPress and
 *   WPGraphQL adaptors moved to their own repositories — `elephentity-wordpress` and
 *   `elephentity-wpgraphql` — in elephentity#79; this rule is what would now catch this
 *   repository reaching back into either of them, alongside catching WordPress leaking
 *   in directly.
 *
 *   This is what keeps the storage abstraction honest. WordPress concepts leak
 *   quietly, and by the time they are load-bearing a second adaptor is no longer
 *   possible. Enforcing it from day one costs nothing; retrofitting it costs a rewrite.
 *
 *   Symbols (WP_*, wp_*, $wpdb, the hook functions) are one leak vector; two others
 *   walked straight past that check because neither is a symbol:
 *
 *   - A word, not a symbol. `SemanticValidator` once carried
 *     `WORDPRESS_HANDLE_LIMIT` and a message naming WordPress in prose — a constant
 *     name and a string literal, not a bare identifier `wp_*` would catch. Checked
 *     against string literals and constant names only, so a driver-agnostic field
 *     merely *describing* what a handle is for stays legal.
 *   - An import, not a call. `CheckCommand` named
 *     `Eleph\WPGraphQL\Conformance\ConformanceChecker` directly, and neither the WP_*
 *     check nor the vocabulary check above catches a namespace: it only broke the day
 *     `eleph check` was resolved as its own package, without the monorepo's autoloader
 *     pulling `Eleph\WPGraphQL\` in for free. `PLATFORM_NAMESPACES` below still names
 *     both, even with neither package present here any more — an import of either is a
 *     missing-class error at best and a stale copy resurrected at worst.
 *
 * Rule 2 — the schema-free runtime.
 *   The packages that ship — runtime, memory — must not reference `Eleph\Schema`
 *   except from the build-time classes named below.
 *
 *   `elephentity/schema` is dev-only: it parses YAML and exists to produce the IR, and
 *   the plan says dev packages never reach production. A shipped class naming one is
 *   how that stops being true, and it did — a compiled storage manifest contained
 *   `Schema\Ir\RelationKind::OneToMany` as a literal, so loading it pulled the spec
 *   compiler into every request of every WordPress project.
 *
 *   The allowlist is deliberately a list of files rather than a namespace convention.
 *   Each entry is a class that runs at build time and could not run at any other, and
 *   adding one should take a moment's thought rather than a directory choice.
 *
 * Uses the tokenizer rather than grep, so prose in a doc comment discussing WordPress
 * is not mistaken for a dependency on it — and neither is a string literal.
 *
 * The code generator used to be checked here too. It lives in its own repository now
 * and has no WordPress stubs to reach for even by accident, so the rule went with it —
 * the WordPress and WPGraphQL adaptors going the same way (elephentity#79) is the same
 * move for the same reason.
 */

const CORE_PACKAGES = ['schema', 'runtime', 'cli'];

/** The packages that ship, and so may not reach for the spec compiler. */
const SHIPPED_PACKAGES = ['runtime', 'memory'];

/**
 * Build-time classes inside a shipped package, allowed to read the IR.
 *
 * Each turns a compiled spec into something the runtime loads later; none of them is
 * reachable from a request. Paths are relative to packages/.
 */
const COMPILERS = [
    'memory/bin/eleph-gen-memory',
];

/** Hook and option functions that are WordPress even without a wp_ prefix. */
const WORDPRESS_FUNCTIONS = [
    'add_action', 'add_filter', 'apply_filters', 'do_action',
    'get_option', 'update_option', 'delete_option', 'register_post_type',
];

/**
 * Words a core package's string literals and constant names may not contain,
 * case-insensitively. Prose in a doc comment is not scanned at all — only the two
 * places a driver's own vocabulary has actually leaked in.
 */
const WORDPRESS_VOCABULARY = ['wordpress'];

/** Namespaces a core package may not import a class from, at all. */
const PLATFORM_NAMESPACES = ['Eleph\\WordPress\\', 'Eleph\\WPGraphQL\\'];

$root = $argv[1] ?? dirname(__DIR__);
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

    // Vocabulary and platform imports are checked against what ships, not what proves
    // it works: a test naming the one real driver by its actual name is exercising
    // driver-agnostic code with real data, not leaking platform knowledge into it — the
    // same reasoning sourceFilesIn() already applies to the schema-free-runtime rule.
    foreach (sourceFilesIn($directory) as $file) {
        foreach (wordpressVocabularyIn($file) as $line => $word) {
            $violations[] = sprintf(
                '%s:%d names "%s", which is WordPress vocabulary',
                substr($file, strlen($root) + 1),
                $line,
                $word,
            );
        }

        foreach (platformImportsIn($file) as $line => $symbol) {
            $violations[] = sprintf(
                '%s:%d imports %s, a platform package',
                substr($file, strlen($root) + 1),
                $line,
                $symbol,
            );
        }
    }
}

if ([] !== $violations) {
    fwrite(STDERR, "ERROR: this repository must stay platform-free.\n");
    fwrite(STDERR, "The WordPress and WPGraphQL adaptors live in elephentity-wordpress and\n");
    fwrite(STDERR, "elephentity-wpgraphql now; nothing here may reference either.\n\n");

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

/**
 * A driver's own vocabulary, in a string literal or a constant name — the two places
 * it leaked in before either was a bare `wp_*` symbol. Doc comments are never
 * tokenised as strings, so prose explaining a driver-agnostic field stays legal.
 *
 * @return array<int, string> line number => offending text
 */
function wordpressVocabularyIn(string $file): array
{
    $source = file_get_contents($file);

    if (false === $source) {
        return [];
    }

    $found = [];
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;

        if (T_CONSTANT_ENCAPSED_STRING === $id) {
            if (containsVocabulary(trim($text, '\'"'))) {
                $found[$line] = $text;
            }

            continue;
        }

        if (T_CONST !== $id) {
            continue;
        }

        // The constant's name: the next non-whitespace token after `const`.
        for ($cursor = $index + 1; $cursor < count($tokens); ++$cursor) {
            $next = $tokens[$cursor];

            if (is_array($next) && T_WHITESPACE === $next[0]) {
                continue;
            }

            if (is_array($next) && T_STRING === $next[0] && containsVocabulary($next[1])) {
                $found[$next[2]] = $next[1];
            }

            break;
        }
    }

    return $found;
}

function containsVocabulary(string $text): bool
{
    $lower = strtolower($text);

    foreach (WORDPRESS_VOCABULARY as $word) {
        if (str_contains($lower, $word)) {
            return true;
        }
    }

    return false;
}

/**
 * A reference to a class in a platform package — `Eleph\WordPress\` or
 * `Eleph\WPGraphQL\` — from a core package. Not a symbol and not a function call, so
 * neither of the other checks in this file sees it; a core package importing one is
 * exactly the leak `eleph check` shipped with once.
 *
 * @return array<int, string> line number => offending symbol
 */
function platformImportsIn(string $file): array
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

        if (T_NAME_QUALIFIED !== $id && T_NAME_FULLY_QUALIFIED !== $id) {
            continue;
        }

        $qualified = ltrim($text, '\\');

        foreach (PLATFORM_NAMESPACES as $namespace) {
            if (str_starts_with($qualified, $namespace)) {
                $found[$line] = $text;

                break;
            }
        }
    }

    return $found;
}
