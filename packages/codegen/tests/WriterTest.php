<?php

declare(strict_types=1);

namespace PheFr\Codegen\Tests;

use FilesystemIterator;
use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\Output\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The writer is the CI gate, so the tests are the three ways a tree can drift.
 */
#[CoversClass(Writer::class)]
final class WriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phefr-writer-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->directory);
    }

    public function testWritingTwiceLeavesEverythingUnchanged(): void
    {
        $writer = new Writer($this->directory);
        $files = $this->files();

        $first = $writer->write($files);

        self::assertSame(['Post.php'], $first->created);
        self::assertFalse($first->isClean());

        $second = $writer->write($files);

        self::assertSame([], $second->created);
        self::assertSame(['Post.php'], $second->unchanged);
        self::assertTrue($second->isClean());
    }

    public function testCheckReportsWithoutWriting(): void
    {
        $report = (new Writer($this->directory))->check($this->files());

        self::assertSame(['Post.php'], $report->created);
        self::assertFileDoesNotExist($this->directory . '/Post.php');
    }

    public function testAHandEditedFileIsReportedAsTampered(): void
    {
        $writer = new Writer($this->directory);
        $writer->write($this->files());

        file_put_contents(
            $this->directory . '/Post.php',
            str_replace('class Post', 'class Postt', (string) file_get_contents($this->directory . '/Post.php')),
        );

        $report = $writer->check($this->files());

        self::assertSame(['Post.php'], $report->tampered);
        self::assertSame(['Post.php'], $report->updated);
        self::assertFalse($report->isClean());
    }

    public function testAFileTheSchemaNoLongerProducesIsReportedAndRemoved(): void
    {
        // No sidecar manifest tracks the tree's contents; regenerating and diffing
        // catches additions and deletions instead.
        $writer = new Writer($this->directory);
        $writer->write($this->files());

        copy($this->directory . '/Post.php', $this->directory . '/Ghost.php');

        self::assertSame(['Ghost.php'], $writer->check($this->files())->deleted);

        $writer->write($this->files());

        self::assertFileDoesNotExist($this->directory . '/Ghost.php');
    }

    public function testADeletedFileIsRecreated(): void
    {
        $writer = new Writer($this->directory);
        $writer->write($this->files());

        unlink($this->directory . '/Post.php');

        self::assertSame(['Post.php'], $writer->check($this->files())->created);
    }

    public function testNestedNamespacesBecomeNestedDirectories(): void
    {
        $writer = new Writer($this->directory);

        $writer->write([
            new GeneratedFile('Contract/Verifier/PostPriceVerifier.php', "namespace X;\n"),
        ]);

        self::assertFileExists($this->directory . '/Contract/Verifier/PostPriceVerifier.php');
    }

    /**
     * @return list<GeneratedFile>
     */
    private function files(): array
    {
        return [new GeneratedFile('Post.php', "namespace App;\n\nfinal class Post\n{\n}\n")];
    }
}
