<?php

declare(strict_types=1);

namespace PheFr\Runtime\UnitOfWork;

use BackedEnum;
use DateTimeInterface;
use JsonException;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Type\ProcessorRegistry;
use RuntimeException;

/**
 * Turns domain values into what the adaptor stores.
 *
 * The last step before a value leaves the framework, and the mirror of the read
 * processors on the way back. Anything the closed primitive set covers is handled
 * here; declared types delegate to their write processor, which by this point has
 * already passed verification.
 */
final readonly class ValueEncoder
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param array<string, string> $fieldTypes "Entity.field" => declared type name.
     */
    public function __construct(
        private array $fieldTypes,
        private ProcessorRegistry $processors,
    ) {
    }

    public function encode(string $entity, string $field, mixed $value): string|int|float|bool|null
    {
        if (null === $value) {
            return null;
        }

        $type = $this->fieldTypes[$entity . '.' . $field] ?? null;

        if (null !== $type && $this->processors->has($type)) {
            return $this->scalar($this->processors->write($type)->write($value), $entity, $field);
        }

        return $this->scalar($value, $entity, $field);
    }

    private function scalar(mixed $value, string $entity, string $field): string|int|float|bool|null
    {
        return match (true) {
            null === $value || is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(self::DATETIME_FORMAT),
            $value instanceof EntityId => $value->raw(),
            is_array($value) => $this->json($value, $entity, $field),
            default => throw new RuntimeException(sprintf(
                '%s.%s holds a %s, which nothing knows how to store. A declared type needs a write processor.',
                $entity,
                $field,
                get_debug_type($value),
            )),
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function json(array $value, string $entity, string $field): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('%s.%s could not be encoded as JSON: %s', $entity, $field, $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}
