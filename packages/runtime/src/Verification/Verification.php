<?php

declare(strict_types=1);

namespace PheFr\Runtime\Verification;

use InvalidArgumentException;

/**
 * The result of verifying a single value.
 *
 * Verifiers return violations rather than throwing, so that a unit of work can run
 * every verifier up front and reject the whole commit with a complete list of
 * problems instead of surfacing them one round trip at a time.
 */
final readonly class Verification
{
    /**
     * @param list<Violation> $violations
     */
    private function __construct(public array $violations)
    {
    }

    public static function ok(): self
    {
        return new self([]);
    }

    /**
     * @throws InvalidArgumentException if called with no violations; use ok() instead.
     */
    public static function failed(Violation ...$violations): self
    {
        if ([] === $violations) {
            throw new InvalidArgumentException(
                'Verification::failed() requires at least one violation; use Verification::ok().',
            );
        }

        return new self(array_values($violations));
    }

    public function isOk(): bool
    {
        return [] === $this->violations;
    }

    /**
     * Combine two results, preserving violation order.
     *
     * Both tiers of verification always run even when the first fails, so violations
     * from the field verifier and the type processor aggregate into one response.
     */
    public function merge(self $other): self
    {
        if ($this->isOk()) {
            return $other;
        }

        if ($other->isOk()) {
            return $this;
        }

        return new self([...$this->violations, ...$other->violations]);
    }
}
