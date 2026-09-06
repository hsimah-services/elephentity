<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\WordPress\Migration\MigrationPlan;
use Eleph\WordPress\Migration\MigrationPlanner;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\DdlCompiler;
use Eleph\WordPress\Sql\Index;
use Eleph\WordPress\Sql\TableSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The planner's value is in what it refuses, so most of these are refusals.
 */
#[CoversClass(MigrationPlanner::class)]
#[CoversClass(DdlCompiler::class)]
final class MigrationPlannerTest extends TestCase
{
    public function testANewTableIsCreated(): void
    {
        $plan = $this->plan(['posts' => $this->posts()], []);

        self::assertTrue($plan->isSafe());
        self::assertCount(1, $plan->statements);
        self::assertStringContainsString('CREATE TABLE `posts`', $plan->statements[0]);
        self::assertStringContainsString('ENGINE=InnoDB', $plan->statements[0]);
        self::assertStringContainsString('ROW_FORMAT=DYNAMIC', $plan->statements[0]);
        self::assertStringContainsString('PRIMARY KEY (`id`)', $plan->statements[0]);
    }

    public function testAnUnchangedSchemaPlansNothing(): void
    {
        self::assertTrue($this->plan(['posts' => $this->posts()], ['posts' => $this->posts()])->isEmpty());
    }

    public function testANewNullableColumnIsAddedAutomatically(): void
    {
        $plan = $this->plan(
            ['posts' => $this->posts()->withColumn(new Column('subtitle', 'VARCHAR(255)', nullable: true))],
            ['posts' => $this->posts()],
        );

        self::assertTrue($plan->isSafe());
        self::assertSame(
            ['ALTER TABLE `posts` ADD COLUMN `subtitle` VARCHAR(255) NULL'],
            $plan->statements,
        );
    }

    public function testANewNotNullColumnWithNoDefaultIsRefused(): void
    {
        // Existing rows would have nothing to put in it, and only a human can say what.
        $plan = $this->plan(
            ['posts' => $this->posts()->withColumn(new Column('subtitle', 'VARCHAR(255)'))],
            ['posts' => $this->posts()],
        );

        self::assertFalse($plan->isSafe());
        self::assertSame('column.notNullWithoutDefault', $plan->refusals[0]->code);
    }

    public function testAChangedColumnTypeIsRefused(): void
    {
        $plan = $this->plan(
            ['posts' => new TableSchema('posts', [
                'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
                'title' => new Column('title', 'VARCHAR(50)'),
            ])],
            ['posts' => $this->posts()],
        );

        self::assertSame('column.changed', $plan->refusals[0]->code);
        self::assertStringContainsString('VARCHAR(255)', $plan->refusals[0]->message);
        self::assertStringContainsString('VARCHAR(50)', $plan->refusals[0]->message);
    }

    public function testAColumnTheSpecNoLongerDeclaresIsRefused(): void
    {
        // Either a rename or a drop; the diff cannot tell, and guessing loses data.
        $plan = $this->plan(
            ['posts' => new TableSchema('posts', [
                'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
                'heading' => new Column('heading', 'VARCHAR(255)', nullable: true),
            ])],
            ['posts' => $this->posts()],
        );

        $codes = array_map(static fn ($r) => $r->code, $plan->refusals);

        self::assertContains('column.unexpected', $codes);
    }

    public function testATableNoEntityMapsToIsRefused(): void
    {
        $plan = $this->plan([], ['posts' => $this->posts()]);

        self::assertSame('table.unexpected', $plan->refusals[0]->code);
    }

    public function testAddingAnIndexIsAutomaticAndDroppingOneIsToo(): void
    {
        // Dropping an index is the one destructive-looking change allowed: it loses
        // no data and is trivially reversible.
        $indexed = $this->posts()->withIndex(new Index('posts_title_idx', ['title']));

        $added = $this->plan(['posts' => $indexed], ['posts' => $this->posts()]);

        self::assertTrue($added->isSafe());
        self::assertSame(
            ['ALTER TABLE `posts` ADD KEY `posts_title_idx` (`title`)'],
            $added->statements,
        );

        $dropped = $this->plan(['posts' => $this->posts()], ['posts' => $indexed]);

        self::assertTrue($dropped->isSafe());
        self::assertSame(
            ['ALTER TABLE `posts` DROP INDEX `posts_title_idx`'],
            $dropped->statements,
        );
    }

    public function testEveryProblemIsReportedInOnePass(): void
    {
        $plan = $this->plan(
            ['posts' => new TableSchema('posts', [
                'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
                'title' => new Column('title', 'VARCHAR(50)'),
                'author' => new Column('author', 'VARCHAR(255)'),
            ])],
            ['posts' => $this->posts(), 'orphan' => $this->posts()],
        );

        $codes = array_map(static fn ($r) => $r->code, $plan->refusals);

        self::assertContains('column.changed', $codes);
        self::assertContains('column.notNullWithoutDefault', $codes);
        self::assertContains('table.unexpected', $codes);
    }

    /**
     * @param array<string, TableSchema> $desired
     * @param array<string, TableSchema> $current
     */
    private function plan(array $desired, array $current): MigrationPlan
    {
        return (new MigrationPlanner())->plan($desired, $current);
    }

    private function posts(): TableSchema
    {
        return new TableSchema('posts', [
            'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
            'title' => new Column('title', 'VARCHAR(255)'),
        ]);
    }
}
