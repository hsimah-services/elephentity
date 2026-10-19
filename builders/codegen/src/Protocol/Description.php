<?php

declare(strict_types=1);

namespace Eleph\Codegen\Protocol;

/**
 * What one builder said it provides.
 *
 * Carried, never interpreted. `provides` is an opaque object this program forwards to
 * the compiler exactly as the IR is forwarded to a builder — and for the same reason:
 * the compiler is the only thing that knows what an integration is, so a builder that
 * starts providing some new kind of thing needs a new compiler and a new builder, and
 * no release of this.
 *
 * A failed description is a Description with errors rather than an exception, so one
 * unusable builder does not stop the others being asked. Every problem in a run is
 * reported together; that is the same rule generating already follows.
 */
final readonly class Description
{
    /**
     * @param array<string, mixed> $provides
     * @param list<string>         $errors
     */
    private function __construct(
        public string $target,
        public array $provides = [],
        public array $errors = [],
    ) {
    }

    /**
     * @param array<string, mixed> $provides
     */
    public static function of(string $target, array $provides): self
    {
        return new self($target, $provides);
    }

    /**
     * @param list<string> $errors
     */
    public static function failed(string $target, array $errors): self
    {
        return new self($target, [], $errors);
    }

    public function isSuccess(): bool
    {
        return [] === $this->errors;
    }
}
