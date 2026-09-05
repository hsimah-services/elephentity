<?php

declare(strict_types=1);

namespace PheFr\Schema\Error;

/**
 * One reason a spec was rejected.
 *
 * Errors are collected rather than thrown, so a single run reports everything wrong
 * with the specs instead of surfacing problems one round trip at a time — the same
 * reasoning that makes field verification return violations.
 */
final readonly class SpecError
{
    public function __construct(
        /** Stable, machine-readable identifier, e.g. "pattern.collision". */
        public string $code,
        public string $message,
        public ?string $file = null,
        /** JSON pointer into the spec document, when the error has a location. */
        public ?string $pointer = null,
    ) {
    }

    public function describe(): string
    {
        $location = $this->file ?? '<unknown file>';

        if (null !== $this->pointer && '' !== $this->pointer) {
            $location .= ' ' . $this->pointer;
        }

        return sprintf('%s: %s [%s]', $location, $this->message, $this->code);
    }
}
