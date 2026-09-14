<?php

declare(strict_types=1);

namespace Eleph\Runtime\Conformance;

/**
 * One thing a verifier found, or one thing it could not check.
 */
final readonly class Signal
{
    public function __construct(
        public Severity $severity,
        public string $message,
    ) {
    }
}
