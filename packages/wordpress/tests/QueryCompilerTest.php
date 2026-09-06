<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Cursor;
use Eleph\Runtime\Storage\Direction;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Offset;
use Eleph\Runtime\Storage\Order;
use Eleph\WordPress\Sql\Column;
use Eleph\WordPress\Sql\CompiledQuery;
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
