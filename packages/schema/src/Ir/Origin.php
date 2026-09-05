<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

/**
 * Where a member came from: the entity's own spec, or a pattern it uses.
 *
 * Tracked so that a sealed-collision error can name both contributors, and so a
 * reader can tell which parts of a resolved entity are its own.
 */
final readonly class Origin
{
    private function __construct(
        public ?string $pattern,
        public string $file,
    ) {
    }

    public static function entity(string $file): self
    {
        return new self(null, $file);
    }

    public static function pattern(string $patternName, string $file): self
    {
        return new self($patternName, $file);
    }

    public function isPattern(): bool
    {
        return null !== $this->pattern;
    }

    public function describe(): string
    {
        return null === $this->pattern
            ? 'the entity spec'
            : sprintf('pattern %s', $this->pattern);
    }
}
