<?php

declare(strict_types=1);

namespace Eleph\Schema\Wire;

use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Ir\ConfigType;

/**
 * An integration definition, over the wire.
 *
 * A builder declares which integrations it provides and the compiler validates specs
 * against them, so the declaration has to survive a pipe. Written out explicitly rather
 * than reflected like the IR: this is four small shapes that change rarely, and being
 * able to read the JSON in `docs/PROTOCOL.md` and match it against this file line for
 * line is worth more here than the generality.
 *
 * `hasDefault` travels rather than being inferred from `default`, because a parameter
 * defaulting to null and one with no default at all mean different things — the second
 * is required.
 */
final readonly class IntegrationCodec
{
    /**
     * @return array<string, mixed>
     */
    public static function encode(IntegrationDefinition $definition): array
    {
        return [
            'description' => $definition->description,
            'projectConfig' => (object) self::encodeParameters($definition->projectConfig),
            'entityConfig' => (object) self::encodeParameters($definition->entityConfig),
            'queryConfig' => (object) self::encodeParameters($definition->queryConfig),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(string $name, array $data): IntegrationDefinition
    {
        $description = $data['description'] ?? '';

        return new IntegrationDefinition(
            name: $name,
            description: is_string($description) ? $description : '',
            projectConfig: self::decodeParameters($data['projectConfig'] ?? []),
            entityConfig: self::decodeParameters($data['entityConfig'] ?? []),
            queryConfig: self::decodeParameters($data['queryConfig'] ?? []),
        );
    }

    /**
     * @param array<string, ConfigParameter> $parameters
     *
     * @return array<string, array<string, mixed>>
     */
    private static function encodeParameters(array $parameters): array
    {
        $encoded = [];

        foreach ($parameters as $name => $parameter) {
            $encoded[$name] = [
                'type' => $parameter->type->value,
                'description' => $parameter->description,
                'of' => $parameter->of?->value,
                'values' => $parameter->values,
                'nullable' => $parameter->nullable,
                'default' => $parameter->default,
                'hasDefault' => $parameter->hasDefault,
            ];
        }

        return $encoded;
    }

    /**
     * @return array<string, ConfigParameter>
     */
    private static function decodeParameters(mixed $value): array
    {
        if (!is_array($value)) {
            throw new WireException('An integration\'s config block must be an object.');
        }

        $parameters = [];

        /** @var mixed $declaration */
        foreach ($value as $name => $declaration) {
            if (!is_string($name) || !is_array($declaration)) {
                throw new WireException('Every config parameter must be named and be an object.');
            }

            $type = $declaration['type'] ?? null;

            if (!is_string($type) || null === ConfigType::tryFrom($type)) {
                throw new WireException(sprintf(
                    'Config parameter "%s" declares type %s, which is not one this build knows.',
                    $name,
                    is_scalar($type) ? '"' . $type . '"' : get_debug_type($type),
                ));
            }

            $of = $declaration['of'] ?? null;
            $description = $declaration['description'] ?? null;
            $values = $declaration['values'] ?? null;

            $parameters[$name] = new ConfigParameter(
                name: $name,
                type: ConfigType::from($type),
                description: is_string($description) ? $description : null,
                of: is_string($of) ? ConfigType::from($of) : null,
                values: is_array($values) ? array_values(array_filter($values, is_string(...))) : null,
                nullable: true === ($declaration['nullable'] ?? false),
                default: $declaration['default'] ?? null,
                hasDefault: true === ($declaration['hasDefault'] ?? false),
            );
        }

        return $parameters;
    }
}
