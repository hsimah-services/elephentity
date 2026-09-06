<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

use LogicException;

/**
 * Typed access to a spec document that has already passed JSON Schema validation.
 *
 * YAML parses to untyped arrays, and static analysis cannot see that validation has
 * already established the shape. This narrows values once, in one place, so the rest
 * of the compiler works in real types.
 *
 * Every failure here is an internal invariant violation, not user error: the JSON
 * Schema should have rejected the document first. Hence LogicException rather than a
 * collected SpecError.
 */
final readonly class SpecReader
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value)) {
            throw new LogicException(sprintf('Expected "%s" to be a string.', $key));
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        return $this->has($key) ? $this->string($key) : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return $default;
        }

        if (!is_bool($value)) {
            throw new LogicException(sprintf('Expected "%s" to be a boolean.', $key));
        }

        return $value;
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_int($value)) {
            throw new LogicException(sprintf('Expected "%s" to be an integer.', $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            throw new LogicException(sprintf('Expected "%s" to be a map.', $key));
        }

        $map = [];

        foreach ($value as $name => $entry) {
            if (!is_string($name)) {
                throw new LogicException(sprintf('Expected "%s" to be keyed by name.', $key));
            }

            $map[$name] = $entry;
        }

        return $map;
    }

    /**
     * A map of named sub-documents, e.g. fields, edges, actions.
     *
     * @return array<string, SpecReader>
     */
    public function readers(string $key): array
    {
        $readers = [];

        foreach ($this->map($key) as $name => $entry) {
            if (!is_array($entry)) {
                throw new LogicException(sprintf('Expected "%s.%s" to be a map.', $key, $name));
            }

            /** @var array<string, mixed> $entry */
            $readers[$name] = new self($entry);
        }

        return $readers;
    }

    public function reader(string $key): ?self
    {
        if (!$this->has($key)) {
            return null;
        }

        $value = $this->data[$key];

        if (!is_array($value)) {
            throw new LogicException(sprintf('Expected "%s" to be a map.', $key));
        }

        /** @var array<string, mixed> $value */
        return new self($value);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!is_array($value)) {
            throw new LogicException(sprintf('Expected "%s" to be a list.', $key));
        }

        $list = [];

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new LogicException(sprintf('Expected every item of "%s" to be a string.', $key));
            }

            $list[] = $entry;
        }

        return $list;
    }
}
