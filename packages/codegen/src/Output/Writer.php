<?php

declare(strict_types=1);

namespace PheFr\Codegen\Output;

use FilesystemIterator;
use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\Signing\SignatureStatus;
use PheFr\Codegen\Signing\Signer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Writes the generated tree, or reports what writing it would change.
 *
 * Check mode is the CI gate: regenerate into memory, compare against disk, and fail on
 * any difference. That covers hand-edited files, stale files the schema no longer
 * produces, and files someone deleted — which is why no sidecar manifest is needed to
 * track the tree's contents.
 */
final readonly class Writer
{
    public function __construct(
        private string $outputDirectory,
        private Signer $signer = new Signer(),
    ) {
    }

    /**
     * @param list<GeneratedFile> $files
     */
    public function check(array $files): WriteReport
    {
        return $this->apply($files, dryRun: true);
    }

    /**
     * @param list<GeneratedFile> $files
     */
    public function write(array $files): WriteReport
    {
        return $this->apply($files, dryRun: false);
    }

    /**
     * @param list<GeneratedFile> $files
     */
    private function apply(array $files, bool $dryRun): WriteReport
    {
        $created = [];
        $updated = [];
        $unchanged = [];
        $tampered = [];
        $expected = [];

        foreach ($files as $file) {
            $absolute = rtrim($this->outputDirectory, '/') . '/' . $file->relativePath;
            $expected[$file->relativePath] = true;

            $signed = $this->signer->sign($file->relativePath, $file->body);
            $existing = is_file($absolute) ? file_get_contents($absolute) : null;

            if (false === $existing) {
                throw new RuntimeException(sprintf('Cannot read %s.', $absolute));
            }

            if (null === $existing) {
                $created[] = $file->relativePath;
            } elseif ($existing === $signed) {
                $unchanged[] = $file->relativePath;

                continue;
            } else {
                $updated[] = $file->relativePath;

                if (SignatureStatus::Tampered === $this->signer->verify($file->relativePath, $existing)) {
                    $tampered[] = $file->relativePath;
                }
            }

            if (!$dryRun) {
                $this->put($absolute, $signed);
            }
        }

        $deleted = $this->stale($expected);

        if (!$dryRun) {
            foreach ($deleted as $relative) {
                @unlink(rtrim($this->outputDirectory, '/') . '/' . $relative);
            }
        }

        return new WriteReport($created, $updated, $unchanged, $deleted, $tampered);
    }

    /**
     * Files present in the output tree that the schema no longer produces.
     *
     * @param array<string, true> $expected
     *
     * @return list<string>
     */
    private function stale(array $expected): array
    {
        if (!is_dir($this->outputDirectory)) {
            return [];
        }

        $stale = [];
        $prefix = strlen(rtrim($this->outputDirectory, '/')) + 1;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->outputDirectory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || 'php' !== $entry->getExtension()) {
                continue;
            }

            $relative = substr($entry->getPathname(), $prefix);

            if (!isset($expected[$relative])) {
                $stale[] = $relative;
            }
        }

        sort($stale);

        return $stale;
    }

    private function put(string $absolute, string $contents): void
    {
        $directory = dirname($absolute);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $directory));
        }

        if (false === file_put_contents($absolute, $contents)) {
            throw new RuntimeException(sprintf('Cannot write %s.', $absolute));
        }
    }
}
