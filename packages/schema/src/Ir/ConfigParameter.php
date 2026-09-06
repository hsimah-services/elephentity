<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * One parameter a pattern accepts.
 *
 * The pattern declares the shape; the compiler checks supplied values against it. That
 * is what lets a WordPress pattern carry WordPress configuration without the core
 * knowing what any of it means — it validates `visibility` against the pattern's own
 * list of permitted values, and has no idea it becomes `'public' => false`.
 */
final readonly class ConfigParameter
{
    /**
     * @param list<string>|null $values Permitted values, when this is an enum.
     */
    public function __construct(
        public string $name,
        public ConfigType $type,
        public ?string $description = null,
        public ?ConfigType $of = null,
        public ?array $values = null,
        public bool $nullable = false,
        public mixed $default = null,
        public bool $hasDefault = false,
    ) {
    }

    /**
     * A parameter with neither a default nor permission to be null must be supplied.
     *
     * Falling back to null in that case would let a missing `singular` reach the
     * generator as an empty string, and fail somewhere far from the spec that omitted
     * it.
     */
    public function isRequired(): bool
    {
        return !$this->hasDefault && !$this->nullable;
    }

    /**
     * Whether a value is acceptable for this parameter.
     */
    public function accepts(mixed $value): bool
    {
        if (null === $value) {
            return $this->nullable;
        }

        return match ($this->type) {
            ConfigType::String => is_string($value),
            ConfigType::Int => is_int($value),
            ConfigType::Bool => is_bool($value),
            ConfigType::Enum => is_string($value) && in_array($value, $this->values ?? [], true),
            ConfigType::List => is_array($value) && $this->acceptsEvery($value),
        };
    }

    public function describeExpectation(): string
    {
        return match ($this->type) {
            ConfigType::Enum => sprintf('one of %s', implode(', ', $this->values ?? [])),
            ConfigType::List => sprintf('a list of %s', $this->of->value ?? 'string'),
            default => sprintf('a %s', $this->type->value),
        };
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function acceptsEvery(array $values): bool
    {
        $element = $this->of ?? ConfigType::String;

        foreach ($values as $value) {
            $ok = match ($element) {
                ConfigType::Int => is_int($value),
                default => is_string($value),
            };

            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
