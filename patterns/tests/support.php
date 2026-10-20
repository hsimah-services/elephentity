<?php

declare(strict_types=1);

/*
 * What the PHP integration tests share: a throwaway project, and the real pipeline run over it.
 *
 * `eleph` is the compiler from install.sh (or $ELEPH); the orchestrator and the builders are
 * this workspace's checkouts, built with cargo. The memory builder declares the driver the
 * test projects use, so a project here needs no platform.
 */

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function eleph(): string
{
    return getenv('ELEPH') ?: 'eleph';
}

function temporaryProject(string $prefix): string
{
    $directory = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    mkdir($directory . '/spec/entities', recursive: true);
    mkdir($directory . '/spec/patterns');

    return $directory;
}

/** @param array<string, array<string, string>> $targets */
function writeConfig(string $project, string $workspace, array $targets, ?string $codegen = null): void
{
    $config = [
        'spec' => 'spec',
        'codegen' => $codegen ?? $workspace . '/elephentity-codegen/bin/eleph-codegen',
        'targets' => [
            'memory' => ['builder' => $workspace . '/elephentity/packages/memory/bin/eleph-gen-memory', 'output' => 'generated/memory'],
            ...$targets,
        ],
    ];
    file_put_contents($project . '/eleph.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @param list<string> $arguments */
function runEleph(string $project, array $arguments): void
{
    $process = proc_open([eleph(), ...$arguments], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $project);
    check(is_resource($process), 'Could not run ' . eleph());
    fclose($pipes[0]);
    check(0 === proc_close($process), 'eleph ' . implode(' ', $arguments) . ' failed.');
}

function removeTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
