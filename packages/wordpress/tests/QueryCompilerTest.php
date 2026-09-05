<?php

declare(strict_types=1);

namespace PheFr\WordPress\Tests;

use PheFr\Runtime\Storage\Comparison;
use PheFr\Runtime\Storage\Criteria;
use PheFr\Runtime\Storage\Direction;
use PheFr\Runtime\Storage\Filter;
use PheFr\Runtime\Storage\Order;
use PheFr\WordPress\Sql\Column;
use PheFr\WordPress\Sql\CompiledQuery;
use PheFr\WordPress\Sql\QueryCompiler;
use PheFr\WordPress\Sql\TableSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(QueryCompiler::class)]
final class QueryCompilerTest extends TestCase
{
    public function testAnUnfilteredSelectIsJustTheTable(): void
    {
        $compiled = $this->compile(new Criteria('Post'));

        self::assertSame('SELECT * FROM `wp_phe_post`', $compiled->sql);
        self::assertSame([], $compiled->bindings);
    }

    public function testValuesBecomePlaceholdersAndNeverReachTheSql(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('title', Comparison::Equals, "'; DROP TABLE --")),
        );

        self::assertSame('SELECT * FROM `wp_phe_post` WHERE `title` = %s', $compiled->sql);
        self::assertSame(["'; DROP TABLE --"], $compiled->bindings);
    }

    public function testCamelCaseFieldsResolveToSnakeCaseColumns(): void
    {
        $compiled = $this->compile(
            (new Criteria('Post'))->where(new Filter('createdAt', Comparison::GreaterThan, '2026-01-01')),
        );

        self::assertStringContainsString('`created_at` > %s', $compiled->sql);
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

        self::assertStringContainsString('`title` IS NULL', $compiled->sql);
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

        self::assertStringContainsString('`id` IN (%s, %s, %s)', $compiled->sql);
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
            'SELECT * FROM `wp_phe_post` WHERE `title` = %s AND `id` > %s ORDER BY `created_at` DESC',
            $compiled->sql,
        );
        self::assertSame(['a', 5], $compiled->bindings);
    }

    public function testAnUnboundedLimitIsCapped(): void
    {
        // A page without a ceiling is how a lazy query stops being lazy.
        $compiled = $this->compile((new Criteria('Post'))->take(999_999));

        self::assertStringEndsWith(' LIMIT 1000', $compiled->sql);
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

        self::assertSame('SELECT COUNT(*) FROM `wp_phe_post` WHERE `title` = %s', $compiled->sql);
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
