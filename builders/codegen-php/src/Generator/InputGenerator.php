<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\ArgumentDefinition;
use Eleph\Gen\Php\Ir\Cardinality;
use Eleph\Gen\Php\Ir\EdgeDefinition;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\FieldDefinition;
use Eleph\Gen\Php\Ir\Primitive;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Naming\TypeMapper;
use Eleph\Gen\Php\Runtime;
use InvalidArgumentException;

/**
 * Emits the bridge between untyped input and a typed mutation.
 *
 * A protocol layer is handed `['dateAdded' => '2026-09-06']` and a setter wants a
 * DateTimeImmutable. Only generated code knows both ends, so the conversion happens
 * here and the gateway stays untyped only at its very edge.
 *
 * Edges arrive the same way and were previously dropped on the floor: `apply()` walked
 * fields only, so `['name' => 'x', 'item' => 1]` created a row with a null foreign key
 * and no error. An edge key is a replacement — an id for a to-one edge, a list of them
 * for a to-many one — because that is what "here is what this edge holds" means when
 * it arrives as a whole value.
 *
 * Immutable fields are settable on create and absent from update, which the mutation
 * itself decides — it knows whether it is creating.
 */
final readonly class InputGenerator
{
    private const SCALARS = ['string', 'int', 'float', 'bool', 'array'];

    public function __construct(
        private Schema $schema,
        private Names $names,
        private TypeMapper $types,
        private Emitter $emitter,
    ) {
    }

    public function generate(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->input($entity);
        $namespace = $this->emitter->open($class);

        $namespace->addUse(Runtime::MUTATION_BUFFER);
        $namespace->addUse(Runtime::VALUE_DECODER);

        $namespace->addUse(InvalidArgumentException::class);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addComment(sprintf('Turns raw input into pending %s changes.', $entity->name));

        $constructor = $type->addMethod('__construct');
        $constructor->addPromotedParameter('decode')->setType(Runtime::VALUE_DECODER)->setPrivate();

        foreach ($this->declaredTypes($entity) as $typeName) {
            $processor = $this->names->readProcessor($typeName);
            $namespace->addUse($processor);

            $constructor->addPromotedParameter(lcfirst($typeName) . 'Reader')
                ->setType($processor)
                ->setPrivate();
        }

        $lines = [];

        foreach ($entity->fields as $field) {
            // Managed fields never arrive as input: the framework stamps them, and a
            // reader for one would be a way to overwrite what it stamped.
            if (null !== $field->managed) {
                continue;
            }

            $phpType = $this->types->forField($entity, $field);

            if (!in_array($phpType, self::SCALARS, true)) {
                $namespace->addUse($phpType);
            }

            if ($field->hasDefault) {
                $declared = $this->schema->type((string) $field->type->declaredType);
                $default = null === $field->default ? 'null' : var_export($field->default, true);
                if (Primitive::Json === ($field->type->primitive ?? $declared?->primitive) && null !== $field->default) {
                    $default = var_export(json_encode($field->default, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
                }
                if (is_string($field->default) && (null !== $field->enum || (null !== $declared && $declared->isEnum()))) {
                    $case = str_replace(' ', '', ucwords(str_replace('_', ' ', $field->default)));
                    $default = $this->emitter->shortName($phpType) . '::' . $case;
                }
                $key = var_export($field->name, true);
                $lines[] = sprintf('if (!$buffer->target()->isPersisted() && !$buffer->isChanged(%s) && !array_key_exists(%s, $input)) {', $key, $key);
                $lines[] = sprintf('    $buffer->set(%s, $this->%s(%s));', $key, $field->name, $default);
                $lines[] = '}';
                $lines[] = '';
            }

            $lines[] = sprintf('if (array_key_exists(%s, $input)) {', var_export($field->name, true));
            $lines[] = sprintf('    $buffer->set(%s, $this->%s($input[%s]));', var_export($field->name, true), $field->name, var_export($field->name, true));
            $lines[] = '}';
            $lines[] = '';

            $reader = $type->addMethod($field->name)
                ->setPrivate()
                ->setReturnType($phpType)
                ->setReturnNullable(true)
                ->setBody($this->conversion($entity, $field, $phpType));

            $reader->addParameter('value')->setType('mixed');
        }

        foreach ($entity->edges as $edge) {
            $key = var_export($edge->name, true);

            $lines[] = sprintf('if (array_key_exists(%s, $input)) {', $key);
            $lines[] = sprintf('    $buffer->edge(%s)->set($this->%s($input[%s]));', $key, $edge->name, $key);
            $lines[] = '}';
            $lines[] = '';

            $namespace->addUse(Runtime::IDENTIFIER);

            $reader = $type->addMethod($edge->name)
                ->setPrivate()
                ->setReturnType('array')
                ->setBody($this->edgeConversion($entity, $edge))
                ->addComment('@return list<Identifier>');

            $reader->addParameter('value')->setType('mixed');
        }

        $apply = $type->addMethod('apply')
            ->setReturnType('void')
            ->setBody([] === $lines ? '' : rtrim(implode("\n", $lines)))
            ->addComment('Seeds declared defaults on create and applies explicit input.')
            ->addComment('Absent update keys and existing pending values are not reset by defaults.')
            ->addComment('')
            ->addComment('@param array<string, mixed> $input');

        $apply->addParameter('buffer')->setType(Runtime::MUTATION_BUFFER);
        $apply->addParameter('input')->setType('array');

        $decodeAction = $type->addMethod('decodeAction')
            ->setReturnType('array')
            ->addComment('@param array<string, mixed> $args')
            ->addComment('@return array<string, mixed>');
        $decodeAction->addParameter('action')->setType('string');
        $decodeAction->addParameter('args')->setType('array');
        $arms = [];
        foreach ($entity->actions as $action) {
            $values = [];
            foreach ($action->arguments as $argument) {
                $values[] = var_export($argument->name, true) . ' => ' . $this->actionConversion($entity, $action->name, $argument);
            }
            $arms[] = sprintf('    %s => [%s],', var_export($action->name, true), implode(', ', $values));
        }
        $decodeAction->setBody(sprintf(
            "return match (\$action) {\n%s\n    default => throw new InvalidArgumentException(sprintf('Unknown action %%s.', \$action)),\n};",
            implode("\n", $arms),
        ));

        return $this->emitter->file($class, $namespace);
    }

    /**
     * An edge key, as the identifiers the buffer wants.
     *
     * Null and the empty list both mean "holds nothing", which is how an edge is
     * cleared through a protocol that has no other way to say it.
     */
    private function edgeConversion(EntityDefinition $entity, EdgeDefinition $edge): string
    {
        $label = var_export(sprintf('%s.%s', $entity->name, $edge->name), true);

        if (Cardinality::One === $edge->cardinality) {
            return sprintf(
                "if (null === \$value) {\n    return [];\n}\n\nreturn [\$this->decode->id(\$value, %s)];",
                $label,
            );
        }

        return sprintf(
            "if (null === \$value) {\n    return [];\n}\n\n"
                . "if (!is_array(\$value)) {\n"
                . "    throw new InvalidArgumentException(sprintf('%%s takes a list of ids.', %s));\n}\n\n"
                . "\$ids = [];\n\n"
                . "foreach (\$value as \$id) {\n    \$ids[] = \$this->decode->id(\$id, %s);\n}\n\n"
                . 'return $ids;',
            $label,
            $label,
        );
    }

    private function conversion(EntityDefinition $entity, FieldDefinition $field, string $phpType): string
    {
        $label = var_export(sprintf('%s.%s', $entity->name, $field->name), true);
        $primitive = $field->type->primitive;

        if (null === $primitive) {
            $typeName = (string) $field->type->declaredType;
            $declared = $this->schema->type($typeName);
            $backing = $declared->primitive ?? Primitive::String;
            if (null !== $declared && $declared->isEnum()) {
                return sprintf("if (null === \$value) {\n    return null;\n}\n\nreturn %s;", $this->decode(Primitive::Enum, $label, $phpType));
            }
            if (null === $declared || !$declared->hasProcessors) {
                return sprintf("if (null === \$value) {\n    return null;\n}\n\nreturn %s;", $this->decode($backing, $label, $phpType));
            }

            $backing = in_array($backing, [Primitive::Json, Primitive::Datetime], true) ? Primitive::String : $backing;
            return sprintf(
                "if (null === \$value) {\n    return null;\n}\n\nreturn \$this->%sReader->read(%s);",
                lcfirst($typeName),
                $this->decode($backing, $label, $phpType),
            );
        }

        return sprintf(
            "if (null === \$value) {\n    return null;\n}\n\nreturn %s;",
            $this->decode($primitive, $label, $phpType),
        );
    }

    private function decode(Primitive $primitive, string $label, string $phpType, string $variable = '$value'): string
    {
        return match ($primitive) {
            Primitive::String, Primitive::Text => sprintf('$this->decode->string(%s, %s)', $variable, $label),
            Primitive::Int => sprintf('$this->decode->int(%s, %s)', $variable, $label),
            Primitive::Float => sprintf('$this->decode->float(%s, %s)', $variable, $label),
            Primitive::Bool => sprintf('$this->decode->bool(%s, %s)', $variable, $label),
            Primitive::Datetime => sprintf('$this->decode->datetime(%s, %s)', $variable, $label),
            Primitive::Id => sprintf('$this->decode->id(%s, %s)', $variable, $label),
            Primitive::Json => sprintf('$this->decode->json(%s, %s)', $variable, $label),
            Primitive::Enum => sprintf(
                '$this->decode->enum(%s::class, %s, %s)',
                $this->emitter->shortName($phpType),
                $variable,
                $label,
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function declaredTypes(EntityDefinition $entity): array
    {
        $types = [];

        foreach ($entity->fields as $field) {
            $name = $field->type->declaredType;

            if (null === $name || null !== $field->managed) {
                continue;
            }

            $declared = $this->schema->type($name);

            if (null === $declared || $declared->isEnum() || !$declared->hasProcessors) {
                continue;
            }

            $types[$name] = true;
        }

        foreach ($entity->actions as $action) {
            foreach ($action->arguments as $argument) {
                $name = $argument->type->declaredType;
                if (null !== $name) {
                    $declared = $this->schema->type($name);
                    if (null !== $declared && !$declared->isEnum() && $declared->hasProcessors) {
                        $types[$name] = true;
                    }
                }
            }
        }

        return array_keys($types);
    }

    private function actionConversion(EntityDefinition $entity, string $action, ArgumentDefinition $argument): string
    {
        $key = var_export($argument->name, true);
        $variable = sprintf('$args[%s]', $key);
        $label = var_export(sprintf('%s.%s.%s', $entity->name, $action, $argument->name), true);
        $phpType = $this->types->forArgument($argument);
        if ($argument->type->isPrimitive()) {
            assert(null !== $argument->type->primitive);
            $decoded = $this->decode($argument->type->primitive, $label, $phpType, $variable);
        } else {
            $declared = $this->schema->type((string) $argument->type->declaredType);
            $primitive = $declared->primitive ?? Primitive::String;
            $decoded = $this->decode(null !== $declared && $declared->isEnum() ? Primitive::Enum : $primitive, $label, $phpType, $variable);
            if (null !== $declared && !$declared->isEnum() && $declared->hasProcessors) {
                $primitive = in_array($primitive, [Primitive::Json, Primitive::Datetime], true) ? Primitive::String : $primitive;
                $decoded = sprintf('$this->%sReader->read(%s)', lcfirst((string) $argument->type->declaredType), $this->decode($primitive, $label, $phpType, $variable));
            }
        }

        return $argument->nullable
            ? sprintf('null === %s ? null : %s', $variable, $decoded)
            : $decoded;
    }
}
