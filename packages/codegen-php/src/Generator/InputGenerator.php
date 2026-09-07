<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\Naming\Emitter;
use Eleph\Codegen\Php\Naming\Names;
use Eleph\Codegen\Php\Naming\TypeMapper;
use Eleph\Runtime\Identity\Identifier;
use Eleph\Runtime\Mutation\MutationBuffer;
use Eleph\Runtime\Query\ValueDecoder;
use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\Schema;
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

        $namespace->addUse(MutationBuffer::class);
        $namespace->addUse(ValueDecoder::class);

        if ([] !== $entity->edges) {
            $namespace->addUse(InvalidArgumentException::class);
        }

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addComment(sprintf('Turns raw input into pending %s changes.', $entity->name));

        $constructor = $type->addMethod('__construct');
        $constructor->addPromotedParameter('decode')->setType(ValueDecoder::class)->setPrivate();

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

            $namespace->addUse(Identifier::class);

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
            ->addComment('Only what the caller supplied. A key that is absent is left alone,')
            ->addComment('which is what makes a partial update partial.')
            ->addComment('')
            ->addComment('@param array<string, mixed> $input');

        $apply->addParameter('buffer')->setType(MutationBuffer::class);
        $apply->addParameter('input')->setType('array');

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
            $backing = $this->schema->type($typeName)->primitive ?? Primitive::String;

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

    private function decode(Primitive $primitive, string $label, string $phpType): string
    {
        return match ($primitive) {
            Primitive::String, Primitive::Text => sprintf('$this->decode->string($value, %s)', $label),
            Primitive::Int => sprintf('$this->decode->int($value, %s)', $label),
            Primitive::Float => sprintf('$this->decode->float($value, %s)', $label),
            Primitive::Bool => sprintf('$this->decode->bool($value, %s)', $label),
            Primitive::Datetime => sprintf('$this->decode->datetime($value, %s)', $label),
            Primitive::Id => sprintf('$this->decode->id($value, %s)', $label),
            Primitive::Json => sprintf('$this->decode->json($value, %s)', $label),
            Primitive::Enum => sprintf(
                '$this->decode->enum(%s::class, $value, %s)',
                $this->emitter->shortName($phpType),
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

        return array_keys($types);
    }
}
