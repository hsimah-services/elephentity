<?php

declare(strict_types=1);

namespace Eleph\Codegen\Output;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Signing\SignatureStatus;
use Eleph\Codegen\Signing\Signer;
use FilesystemIterator;
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
    /**
     * @param list<string> $ownedExtensions Extensions this target may delete, no dot.
     * @param list<string> $reserved        Subdirectories another target owns, relative to this one.
     */
    public function __construct(
        private string $outputDirectory,
        private Signer $signer = new Signer(),
        private array $ownedExtensions = ['php'],
        private array $reserved = [],
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
     * Restricted to the extensions the target declared. Sweeping everything would be
     * simpler and is arguably justified — an output directory is machine-owned — but it
     * turns a mistyped `output` into data loss, and deleting is the one thing worth
     * being timid about.
     *
     * Restricted again to what no other target owns. A target may write inside another's
     * directory — the WordPress manifests are PHP, loaded by the PHP runtime, and belong
     * in the PHP tree even though the PHP builder cannot produce them — and without this
     * the outer target would sweep the inner one's files on every run, since they share
     * an extension. The exclusions come from every *configured* target rather than the
     * ones this run selected, so narrowing with `--targets` cannot reintroduce it.
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
            if (!$entry instanceof SplFileInfo || !in_array($entry->getExtension(), $this->ownedExtensions, true)) {
                continue;
            }

            $relative = substr($entry->getPathname(), $prefix);

            if (!isset($expected[$relative]) && !$this->isReserved($relative)) {
                $stale[] = $relative;
            }
        }

        sort($stale);

        return $stale;
    }

    /**
     * Whether a path belongs to a target that writes inside this one's directory.
     *
     * Matched on a path-segment boundary, so `wordpress` reserves `wordpress/manifest.php`
     * and not `wordpress-notes.php`, which is a different file that merely starts the
     * same way.
     */
    private function isReserved(string $relative): bool
    {
        foreach ($this->reserved as $directory) {
            if (str_starts_with($relative, trim($directory, '/') . '/')) {
                return true;
            }
        }

        return false;
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
