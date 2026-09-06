<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Verification;

use Eleph\Runtime\Verification\Verification;
use Eleph\Runtime\Verification\Violation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Verification::class)]
#[CoversClass(Violation::class)]
final class VerificationTest extends TestCase
{
    public function testOkHasNoViolations(): void
    {
        $result = Verification::ok();

        self::assertTrue($result->isOk());
        self::assertSame([], $result->violations);
    }

    public function testFailedCarriesEveryViolation(): void
    {
        $result = Verification::failed(
            new Violation('money.negative', 'Must not be negative.'),
            new Violation('money.precision', 'Must be whole cents.'),
        );

        self::assertFalse($result->isOk());
        self::assertCount(2, $result->violations);
        self::assertSame('money.negative', $result->violations[0]->code);
    }

    public function testFailedRejectsAnEmptyViolationList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Verification::failed();
    }

    public function testMergePreservesOrderAcrossTiers(): void
    {
        $field = Verification::failed(new Violation('post.price.tooHigh', 'Too high.'));
        $type = Verification::failed(new Violation('money.negative', 'Must not be negative.'));

        $merged = $field->merge($type);

        self::assertCount(2, $merged->violations);
        self::assertSame('post.price.tooHigh', $merged->violations[0]->code);
        self::assertSame('money.negative', $merged->violations[1]->code);
    }

    public function testMergingWithOkIsIdentity(): void
    {
        $failed = Verification::failed(new Violation('money.negative', 'Must not be negative.'));

        self::assertSame($failed, $failed->merge(Verification::ok()));
        self::assertSame($failed, Verification::ok()->merge($failed));
    }
}
