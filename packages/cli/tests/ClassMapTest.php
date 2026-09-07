<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\ClassMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The class map is how `eleph check` reaches a tree it did not generate, so what it has
 * to survive is a tree that is missing, stale or truncated — the states a generated
 * directory is actually found in when someone forgot to run `generate`.
 */
#[CoversClass(ClassMap::class)]
final class ClassMapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/eleph-classmap-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/Post', 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (['Post/Post.php', ClassMap::PATH] as $file) {
            $path = $this->directory . '/' . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }

        foreach ([$this->directory . '/Post', $this->directory] as $path) {
            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    public function testAnEntityResolvesToItsGeneratedClass(): void
    {
        $map = $this->write();

        self::assertSame('Fixture\\Post\\Post', $map->classFor('Post'));
    }

    public function testAnEntityTheTreeDoesNotHoldResolvesToItsOwnName(): void
    {
        // Returning the name is what makes the failure legible downstream: the
        // conformance checker then reports a type whose class does not exist, which is
        // the true statement about an entity nobody generated.
        self::assertSame('Ghost', $this->write()->classFor('Ghost'));
    }

    public function testTheTreeLoadsWithoutTheProjectsAutoloader(): void
    {
        $class = 'Fixture\\Post\\Post';

        file_put_contents(
            $this->directory . '/Post/Post.php',
            "<?php\n\nnamespace Fixture\\Post;\n\nfinal class Post\n{\n}\n",
        );

        $map = $this->write();

        self::assertFalse(class_exists($class, false));

        $map->autoload();

        self::assertTrue(class_exists($class));
    }

    public function testAMissingMapSaysToGenerateFirst(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Run `eleph generate` before checking conformance/');

        ClassMap::load($this->directory);
    }

    public function testAMapThatReturnsNothingUsableIsRefused(): void
    {
        file_put_contents($this->directory . '/' . ClassMap::PATH, "<?php\n\nreturn 'nope';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not return a map/');

        ClassMap::load($this->directory);
    }

    public function testAMapMissingASectionIsRefused(): void
    {
        file_put_contents(
            $this->directory . '/' . ClassMap::PATH,
            "<?php\n\nreturn ['entities' => []];\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/carries no "classes" map/');

        ClassMap::load($this->directory);
    }

    public function testAMapWithANonStringEntryIsRefused(): void
    {
        file_put_contents(
            $this->directory . '/' . ClassMap::PATH,
            "<?php\n\nreturn ['entities' => ['Post' => 42], 'classes' => []];\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/entry in "entities" that is not a string/');

        ClassMap::load($this->directory);
    }

    private function write(): ClassMap
    {
        file_put_contents($this->directory . '/' . ClassMap::PATH, <<<'PHP'
            <?php

            return [
                'entities' => ['Post' => 'Fixture\Post\Post'],
                'classes' => ['Fixture\Post\Post' => 'Post/Post.php'],
            ];

            PHP);

        return ClassMap::load($this->directory);
    }
}
