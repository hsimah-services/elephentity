<?php

declare(strict_types=1);

namespace Eleph\Schema\Wire;

use BackedEnum;
use Eleph\Schema\Ir\ActionDefinition;
use Eleph\Schema\Ir\ArgumentDefinition;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\PatternDeclaration;
use Eleph\Schema\Ir\PolicyDefinition;
use Eleph\Schema\Ir\ProjectDefinition;
use Eleph\Schema\Ir\QueryDefinition;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Ir\TriggerDefinition;
use Eleph\Schema\Ir\TriggerEvent;
use Eleph\Schema\Ir\TypeDefinition;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;
use UnitEnum;

/**
 * The IR as JSON, so a generator written in any language can read it.
 *
 * Reflection over the constructors rather than a hand-written encoder per class: the IR
 * is 19 plain value objects with promoted properties and no behaviour in their shape, so
 * a generic walk is both shorter and impossible to leave half-updated when a field is
 * added. The price is the one thing reflection cannot see — what a collection holds —
 * which is why COLLECTIONS exists.
 *
 * Deliberately not a projection of the IR onto a separate set of wire classes. That is
 * what a compatibility promise would need, and there is none before 1.0: the internal
 * shape *is* the wire shape, and when it changes, builders change with it. See
 * docs/PLAN.md §15.
 *
 * Enums travel as their backing value, or their case name when they have none, because
 * the reflected parameter type says which to reconstruct.
 */
final readonly class IrCodec
{
    /**
     * The wire format's version, carried in the envelope.
     *
    * Bumped whenever a builder that understood the old shape would misread the new one
    * — a removed field, a renamed one, a changed meaning. Although the policy fields
    * are optional, a builder that silently drops them generates an entirely ungated
    * tree, so this change must be loud at the version boundary.
     */
    public const VERSION = '1.1';

    /**
     * What each array-typed constructor parameter holds.
     *
     * Reflection reports `array` and stops; the element type lives only in a docblock,
     * and parsing docblocks to drive deserialisation is a fragile way to be clever.
     * Anything absent here is plain data — scalars, or config the core never interprets
     * — and travels untouched.
     *
     * @var array<class-string, array<string, class-string>>
     */
    private const COLLECTIONS = [
        Schema::class => [
            'entities' => EntityDefinition::class,
            'types' => TypeDefinition::class,
            'patterns' => PatternDeclaration::class,
        ],
        EntityDefinition::class => [
            'fields' => FieldDefinition::class,
            'edges' => EdgeDefinition::class,
            'queries' => QueryDefinition::class,
            'actions' => ActionDefinition::class,
            'triggers' => TriggerDefinition::class,
            'readPolicies' => PolicyDefinition::class,
            'writePolicies' => PolicyDefinition::class,
        ],
        PatternDeclaration::class => [
            'fields' => FieldDefinition::class,
            'edges' => EdgeDefinition::class,
            'readPolicies' => PolicyDefinition::class,
            'writePolicies' => PolicyDefinition::class,
        ],
        QueryDefinition::class => ['arguments' => ArgumentDefinition::class],
        ActionDefinition::class => ['arguments' => ArgumentDefinition::class],
        TriggerDefinition::class => ['events' => TriggerEvent::class],
    ];

    /**
     * Array parameters that are string-keyed maps rather than lists.
     *
     * JSON tells them apart and PHP does not: an empty map and an empty list are both
     * `[]`, and `json_encode` picks the wrong one half the time. A builder in another
     * language then gets `[]` where its types say object, which is exactly the kind of
     * papercut that makes a format hostile to the languages it exists to serve.
     *
     * Every collection in COLLECTIONS is a map except one — a trigger's `events` is a
     * list — so this is declared separately rather than derived.
     *
     * @var array<class-string, list<string>>
     */
    private const MAPS = [
        Schema::class => ['entities', 'types', 'patterns'],
        EntityDefinition::class => ['fields', 'edges', 'queries', 'actions', 'triggers', 'readPolicies', 'writePolicies', 'config'],
        PatternDeclaration::class => ['fields', 'edges', 'readPolicies', 'writePolicies'],
        ActionDefinition::class => ['arguments'],
        QueryDefinition::class => ['arguments'],
    ];

    /**
     * Maps whose values are themselves maps of settings the core never interprets.
     *
     * An integration declared with no settings is the common case, and it is precisely
     * the one that misencodes.
     *
     * @var array<class-string, list<string>>
     */
    private const MAP_OF_MAPS = [
        EntityDefinition::class => ['integrations'],
        QueryDefinition::class => ['integrations'],
        ProjectDefinition::class => ['integrations'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function encode(Schema $schema): array
    {
        return self::encodeObject($schema);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): Schema
    {
        $schema = self::decodeObject(Schema::class, $data);

        if (!$schema instanceof Schema) {
            throw new WireException('Decoding the IR did not produce a Schema.');
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeObject(object $subject): array
    {
        $encoded = [];

        foreach (self::parametersOf($subject::class) as $parameter) {
            $name = $parameter->getName();

            /** @var mixed $value */
            $value = $subject->{$name};

            $encoded[$name] = self::shape(self::encodeValue($value), $subject::class, $name);
        }

        return $encoded;
    }

    /**
     * @param class-string $owner
     */
    private static function shape(mixed $encoded, string $owner, string $property): mixed
    {
        if (!is_array($encoded)) {
            return $encoded;
        }

        if (in_array($property, self::MAP_OF_MAPS[$owner] ?? [], true)) {
            $settings = [];

            /** @var mixed $value */
            foreach ($encoded as $key => $value) {
                $settings[$key] = is_array($value) ? (object) $value : $value;
            }

            return (object) $settings;
        }

        return in_array($property, self::MAPS[$owner] ?? [], true) ? (object) $encoded : $encoded;
    }

    private static function encodeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            return self::encodeObject($value);
        }

        if (is_array($value)) {
            $encoded = [];

            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $encoded[$key] = self::encodeValue($item);
            }

            return $encoded;
        }

        return $value;
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $data
     */
    private static function decodeObject(string $class, array $data): object
    {
        $arguments = [];

        foreach (self::parametersOf($class) as $parameter) {
            $name = $parameter->getName();

            if (!array_key_exists($name, $data)) {
                if (!$parameter->isDefaultValueAvailable()) {
                    throw new WireException(sprintf('%s is missing required field "%s".', $class, $name));
                }

                /** @var mixed $default */
                $default = $parameter->getDefaultValue();
                $arguments[] = $default;

                continue;
            }

            $arguments[] = self::decodeValue($data[$name], $parameter, $class);
        }

        // Not `new $class(...)`: four IR classes seal their constructor behind a named
        // one — TypeReference::primitive(), Origin::spec() — and those factories have
        // different shapes from each other, so a decoder that called them would need a
        // special case per class. Invoking the constructor reflectively runs in class
        // scope, which readonly properties allow exactly once, and is the same
        // initialisation the factories themselves perform.
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            throw new WireException(sprintf('%s has no constructor to read.', $class));
        }

        $constructor->invokeArgs($instance, $arguments);

        return $instance;
    }

    /**
     * @param class-string $owner
     */
    private static function decodeValue(mixed $value, ReflectionParameter $parameter, string $owner): mixed
    {
        if (null === $value) {
            return null;
        }

        // encode() emits stdClass for map-shaped fields, and json_decode with assoc
        // gives arrays, so decoding has to accept both forms of the same thing.
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if ('array' === $name) {
            if (!is_array($value)) {
                throw new WireException(sprintf(
                    '%s::$%s should be a list or object, got %s.',
                    $owner,
                    $parameter->getName(),
                    get_debug_type($value),
                ));
            }

            $element = self::COLLECTIONS[$owner][$parameter->getName()] ?? null;

            if (null === $element) {
                // Config and integration settings are arbitrary data the core never
                // interprets, but encode() may have shaped maps inside them as objects,
                // so they still have to come back as plain arrays throughout.
                return self::plain($value);
            }

            $decoded = [];

            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $decoded[$key] = self::decodeElement($element, $item, $owner, $parameter->getName());
            }

            return $decoded;
        }

        if (enum_exists($name)) {
            return self::decodeEnum($name, $value, $owner, $parameter->getName());
        }

        if (class_exists($name)) {
            if (!is_array($value)) {
                throw new WireException(sprintf(
                    '%s::$%s should be an object, got %s.',
                    $owner,
                    $parameter->getName(),
                    get_debug_type($value),
                ));
            }

            /** @var array<string, mixed> $value */
            return self::decodeObject($name, $value);
        }

        return $value;
    }

    /**
     * Arbitrary data, with every stdClass encode() produced turned back into an array.
     */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $plain = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $plain[$key] = self::plain($item);
        }

        return $plain;
    }

    /**
     * @param class-string $element
     * @param class-string $owner
     */
    private static function decodeElement(string $element, mixed $item, string $owner, string $property): mixed
    {
        if (enum_exists($element)) {
            return self::decodeEnum($element, $item, $owner, $property);
        }

        if ($item instanceof stdClass) {
            $item = (array) $item;
        }

        if (!is_array($item)) {
            throw new WireException(sprintf(
                '%s::$%s should hold objects, got %s.',
                $owner,
                $property,
                get_debug_type($item),
            ));
        }

        /** @var array<string, mixed> $item */
        return self::decodeObject($element, $item);
    }

    /**
     * @param class-string $enum
     * @param class-string $owner
     */
    private static function decodeEnum(string $enum, mixed $value, string $owner, string $property): UnitEnum
    {
        /** @var array<UnitEnum> $cases */
        $cases = $enum::cases();

        foreach ($cases as $case) {
            $matches = $case instanceof BackedEnum ? $case->value === $value : $case->name === $value;

            if ($matches) {
                return $case;
            }
        }

        throw new WireException(sprintf(
            '%s::$%s has no case matching %s.',
            $owner,
            $property,
            var_export($value, true),
        ));
    }

    /**
     * @param class-string $class
     *
     * @return list<ReflectionParameter>
     */
    private static function parametersOf(string $class): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if (null === $constructor) {
            throw new WireException(sprintf('%s has no constructor to read.', $class));
        }

        return $constructor->getParameters();
    }
}
