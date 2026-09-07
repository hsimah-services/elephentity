<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Direction;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Order;
use Eleph\Runtime\Storage\RelationKind;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\CompiledQuery;
use Eleph\WordPress\Sql\EdgePlacement;
use Eleph\WordPress\Sql\QueryCompiler;
use Eleph\WordPress\Sql\TableSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(QueryCompiler::class)]
final class QueryCompilerTest extends TestCase
{
    public function testAnUnfilteredSelectIsJustTheTable(): void
    {
        $compiled = $this->compile(new Criteria('Post'));

        self::assertSame('SELECT `wp_phe_post`.* FROM `wp_phe_post`', $compiled->sql);
        self::assertSame([], $compiled->bindings);
    }

    public function testValuesBecomePlaceholdersAndNeverReachTheSql(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('title', Comparison::Equals, "'; DROP TABLE --")),
        );

        self::assertSame(
            'SELECT `wp_phe_post`.* FROM `wp_phe_post` WHERE `wp_phe_post`.`title` = %s',
            $compiled->sql,
        );
        self::assertSame(["'; DROP TABLE --"], $compiled->bindings);
    }

    public function testCamelCaseFieldsResolveToSnakeCaseColumns(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('createdAt', Comparison::GreaterThan, '2026-01-01')),
        );

        self::assertStringContainsString('`wp_phe_post`.`created_at` > %s', $compiled->sql);
    }

    public function testAnUnknownFieldIsRefusedRatherThanQuoted(): void
    {
        // Identifiers cannot be parameterised, so the only safe move is to refuse
        // anything the table does not declare.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no column for field "nope"');

        $this->compile((new Criteria('Post'))->where(new Filter('nope', Comparison::Equals, 1)));
    }

    public function testNullChecksTakeNoBindings(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('title', Comparison::IsNull)),
        );

        self::assertStringContainsString('`wp_phe_post`.`title` IS NULL', $compiled->sql);
        self::assertSame([], $compiled->bindings);
    }

    public function testAnEmptyInSetBecomesAFalseConditionRatherThanASyntaxError(): void
    {
        // IN () does not parse in MySQL, and an empty set matches nothing.
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('id', Comparison::In, [])),
        );

        self::assertStringContainsString('1 = 0', $compiled->sql);

        $negated = $this->compile(
            (new Criteria('Post'))->where(new Filter('id', Comparison::NotIn, [])),
        );

        self::assertStringContainsString('1 = 1', $negated->sql);
    }

    public function testAnInSetGetsOnePlaceholderPerValue(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('id', Comparison::In, [1, 2, 3])),
        );

        self::assertStringContainsString('`wp_phe_post`.`id` IN (%s, %s, %s)', $compiled->sql);
        self::assertSame([1, 2, 3], $compiled->bindings);
    }

    public function testLikeWildcardsInTheValueAreEscaped(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('title', Comparison::StartsWith, '100%')),
        );

        self::assertSame(['100\\%%'], $compiled->bindings);
    }

    public function testFiltersAreConjunctiveAndOrderedAsGiven(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))
                ->where(new Filter('title', Comparison::Equals, 'a'))
                ->where(new Filter('id', Comparison::GreaterThan, 5))
                ->orderBy(new Order('createdAt', Direction::Descending)),
        );

        self::assertSame(
            'SELECT `wp_phe_post`.* FROM `wp_phe_post`'
                . ' WHERE `wp_phe_post`.`title` = %s AND `wp_phe_post`.`id` > %s'
                . ' ORDER BY `wp_phe_post`.`created_at` DESC',
            $compiled->sql,
        );
        self::assertSame(['a', 5], $compiled->bindings);
    }

    public function testAnUnboundedLimitIsCapped(): void
    {
        // A page without a ceiling is how a lazy query stops being lazy. The extra row
        // is deliberate: its presence is how hasNextPage is answered without a second
        // query, and the adaptor drops it before anyone sees the page.
        $compiled = $this->compile((new Criteria('Post'))->take(999_999));

        self::assertStringEndsWith(' LIMIT 1001', $compiled->sql);
    }

    public function testACursorBecomesAnOffset(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->take(20, (new Offset(40))->toCursor()),
        );

        self::assertStringEndsWith(' LIMIT 21 OFFSET 40', $compiled->sql);
    }

    public function testAnUnreadableCursorRestartsTheListRatherThanFailing(): void
    {
        // A cursor from an older format should not break a page someone is looking at.
        $compiled = $this->compile((new Criteria('Post'))->take(20, Cursor::of('nonsense')));

        self::assertStringEndsWith(' LIMIT 21', $compiled->sql);
    }

    public function testCountingDropsOrderingAndLimits(): void
    {
        $compiled = (new QueryCompiler())->count(
            $this->table(),
            (new Criteria('Post'))
                ->where(new Filter('title', Comparison::Equals, 'a'))
                ->orderBy(new Order('id'))
                ->take(10),
        );

        self::assertSame(
            'SELECT COUNT(*) FROM `wp_phe_post` WHERE `wp_phe_post`.`title` = %s',
            $compiled->sql,
        );
    }

    public function testAForwardEdgeWithTheKeyOnThisTableNeedsNoJoin(): void
    {
        // Post.comments puts post_id on the comment table, so asking for a post's
        // comments is a filter on the table already being read.
        $compiled = $this->compileFor(
            $this->commentTable(),
            (new Criteria('Comment'))->linkedTo(EdgeFilter::along('Post', 'comments', EntityId::of(1))),
        );

        self::assertSame(
            'SELECT `wp_phe_comment`.* FROM `wp_phe_comment` WHERE `wp_phe_comment`.`post_id` IN (%d)',
            $compiled->sql,
        );
    }

    public function testTheSameEdgeReadBackwardsJoinsInstead(): void
    {
        // "Which post is this comment on" is not a second edge; it is Post.comments
        // from the far end, and the key has not moved.
        $compiled = $this->compileFor(
            $this->table(),
            (new Criteria('Post'))->linkedTo(EdgeFilter::back('Post', 'comments', EntityId::of(10))),
        );

        self::assertSame(
            'SELECT `wp_phe_post`.* FROM `wp_phe_post`'
                . ' INNER JOIN `wp_phe_comment` `l1` ON `l1`.`post_id` = `wp_phe_post`.`id`'
                . ' WHERE `l1`.`id` IN (%d)',
            $compiled->sql,
        );
        self::assertSame(['10'], $compiled->bindings);
    }

    public function testAManyToOneReadBackwardsNeedsNoJoinEither(): void
    {
        // Inventory.item keeps item_id local, so "which entries are for this item" is
        // a filter on the inventory table — the mirror of the first case.
        $compiled = $this->compileFor(
            $this->commentTable(),
            (new Criteria('Comment'))->linkedTo(EdgeFilter::back('Comment', 'post', EntityId::of(1))),
        );

        self::assertSame(
            'SELECT `wp_phe_comment`.* FROM `wp_phe_comment` WHERE `wp_phe_comment`.`post_id` IN (%d)',
            $compiled->sql,
        );
    }

    public function testAJoinTableSwapsItsTwoColumnsWhenReadBackwards(): void
    {
        $forward = $this->compileFor(
            $this->tagTable(),
            (new Criteria('Tag'))->linkedTo(EdgeFilter::along('Post', 'tags', EntityId::of(1))),
        );

        $backward = $this->compileFor(
            $this->table(),
            (new Criteria('Post'))->linkedTo(EdgeFilter::back('Post', 'tags', EntityId::of(7))),
        );

        self::assertStringContainsString('ON `l1`.`tag_id` = `wp_phe_tag`.`id`', $forward->sql);
        self::assertStringContainsString('WHERE `l1`.`post_id` IN (%d)', $forward->sql);

        self::assertStringContainsString('ON `l1`.`post_id` = `wp_phe_post`.`id`', $backward->sql);
        self::assertStringContainsString('WHERE `l1`.`tag_id` IN (%d)', $backward->sql);
    }

    private function compileFor(TableSchema $table, Criteria $criteria): CompiledQuery
    {
        return (new QueryCompiler(placements: $this->placements()))->select($table, $criteria);
    }

    /**
     * @return array<string, EdgePlacement>
     */
    private function placements(): array
    {
        return [
            // One-to-many: the key sits on the far side.
            'Post.comments' => new EdgePlacement(
                'Post',
                'comments',
                'Comment',
                RelationKind::OneToMany,
                'wp_phe_comment',
                'post_id',
                targetTable: 'wp_phe_comment',
            ),
            // Many-to-one: the key is local.
            'Comment.post' => new EdgePlacement(
                'Comment',
                'post',
                'Post',
                RelationKind::ManyToOne,
                'wp_phe_comment',
                'post_id',
                targetTable: 'wp_phe_post',
            ),
            'Post.tags' => new EdgePlacement(
                'Post',
                'tags',
                'Tag',
                RelationKind::ManyToMany,
                'wp_phe_post_tags',
                'post_id',
                'tag_id',
                'wp_phe_tag',
            ),
        ];
    }

    private function commentTable(): TableSchema
    {
        return new TableSchema('wp_phe_comment', [
            'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
            'post_id' => new Column('post_id', 'BIGINT UNSIGNED', nullable: true),
        ]);
    }

    private function tagTable(): TableSchema
    {
        return new TableSchema('wp_phe_tag', [
            'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
            'label' => new Column('label', 'VARCHAR(255)'),
        ]);
    }

    private function compile(Criteria $criteria): CompiledQuery
    {
        return (new QueryCompiler())->select($this->table(), $criteria);
    }

    private function table(): TableSchema
    {
        return new TableSchema('wp_phe_post', [
            'id' => new Column('id', 'BIGINT UNSIGNED', autoIncrement: true),
            'title' => new Column('title', 'VARCHAR(200)'),
            'created_at' => new Column('created_at', 'DATETIME'),
        ]);
    }
}
