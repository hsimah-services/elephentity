<?php

declare(strict_types=1);

namespace Eleph\Cli\Tests;

use Eleph\Cli\Installed;
use Eleph\Schema\Spec\SpecKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `Installed` pools `provides.drivers` and `provides.integrations` today; this covers
 * the same pooling for `provides.patterns` and `provides.types` — a package's own
 * spec documents, decoded off the wire rather than read off disk.
 */
#[CoversClass(Installed::class)]
final class InstalledTest extends TestCase
{
    public function testPoolsPatternsAndTypesFromEveryTarget(): void
    {
        $installed = Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => [
                    'drivers' => ['wordpress'],
                    'patterns' => [
                        'Taxonomy' => [
                            'pattern' => 'Taxonomy',
                            'requires' => ['driver' => 'wordpress'],
                        ],
                    ],
                ],
                'php' => [
                    'types' => [
                        'PostStatus' => [
                            'type' => 'PostStatus',
                            'primitive' => 'string',
                            'values' => ['draft', 'published'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, $installed->patterns);
        self::assertSame(SpecKind::Pattern, $installed->patterns[0]->kind);
        self::assertSame('Taxonomy', $installed->patterns[0]->name());
        self::assertSame('wordpress:patterns/Taxonomy.yml', $installed->patterns[0]->file);

        self::assertCount(1, $installed->types);
        self::assertSame(SpecKind::Type, $installed->types[0]->kind);
        self::assertSame('PostStatus', $installed->types[0]->name());
        self::assertSame('php:types/PostStatus.yml', $installed->types[0]->file);
    }

    public function testNoneHasNoPooledPatternsOrTypes(): void
    {
        $installed = Installed::none();

        self::assertSame([], $installed->patterns);
        self::assertSame([], $installed->types);
        self::assertNull($installed->storageRules->forDriver('wordpress'));
    }

    public function testPoolsStorageRulesAgainstEveryDriverTheTargetDeclares(): void
    {
        $installed = Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => [
                    'drivers' => ['wordpress'],
                    'storage' => [
                        'handle' => [
                            'maxLength' => 20,
                            'pattern' => '/^[a-z][a-z0-9_-]*$/',
                            'reserved' => ['post', 'page'],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $rules = $installed->storageRules->forDriver('wordpress');

        self::assertNotNull($rules);
        self::assertSame(20, $rules->maxHandleLength);
        self::assertSame('/^[a-z][a-z0-9_-]*$/', $rules->handlePattern);
        self::assertSame(['post', 'page'], $rules->reservedHandles);
    }

    public function testADriverWithNoStorageDeclarationHasNoPooledRules(): void
    {
        $installed = Installed::fromJson(json_encode([
            'targets' => [
                'memory' => ['drivers' => ['memory']],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertNull($installed->storageRules->forDriver('memory'));
    }

    public function testStorageDeclaredAsSomethingOtherThanAnObjectFailsNamingTheTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The wordpress builder declared "storage" as something other than an object\.$/');

        Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => ['drivers' => ['wordpress'], 'storage' => 'nope'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testAMalformedStorageDeclarationFailsNamingTheTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The wordpress builder declared storage rules unusably: /');

        Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => ['drivers' => ['wordpress'], 'storage' => ['handle' => ['maxLength' => 'nope']]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testPatternsDeclaredAsSomethingOtherThanAnObjectFailsNamingTheTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The wordpress builder declared "patterns" as something other than an object\.$/');

        Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => ['patterns' => 'nope'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testAnUnnamedPatternFailsNamingTheTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The wordpress builder declared an unnamed pattern\.$/');

        Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => ['patterns' => [0 => 'not-an-object']],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testAPatternWithoutAPatternKeyFailsNamingTheTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The wordpress builder declared pattern "Taxonomy" without a "pattern" key\.$/');

        Installed::fromJson(json_encode([
            'targets' => [
                'wordpress' => ['patterns' => ['Taxonomy' => ['requires' => ['driver' => 'wordpress']]]],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testATypeWithoutATypeKeyFailsNamingTheTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^The php builder declared type "PostStatus" without a "type" key\.$/');

        Installed::fromJson(json_encode([
            'targets' => [
                'php' => ['types' => ['PostStatus' => ['primitive' => 'string']]],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
