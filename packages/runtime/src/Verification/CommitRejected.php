<?php

declare(strict_types=1);

namespace Eleph\Runtime\Verification;

use RuntimeException;

/**
 * A commit refused because verification failed.
 *
 * Carries every violation from every field of every mutation in the unit of work.
 * That completeness is the reason verifiers return violations rather than throwing:
 * a caller submitting fifteen fields learns about all fifteen problems at once
 * instead of one per round trip.
 */
final class CommitRejected extends RuntimeException
{
    /**
     * @param list<FieldViolation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(sprintf(
            "The commit was rejected by %d violation(s):\n  %s",
            count($violations),
            implode("\n  ", array_map(
                static fn (FieldViolation $violation): string => $violation->describe(),
                $violations,
            )),
        ));
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_unique(array_map(
            static fn (FieldViolation $violation): string => $violation->path(),
            $this->violations,
        )));
    }
}
