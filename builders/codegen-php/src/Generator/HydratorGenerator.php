<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\FieldDefinition;
use Eleph\Gen\Php\Ir\Primitive;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Naming\TypeMapper;
use Eleph\Gen\Php\Runtime;

/**
 * Emits the class that turns a stored row into an entity.
 *
 * Generated because only generated code can call a generated constructor with exact
 * types. One private method per field keeps hydrate() readable and gives each
 * coercion a name that appears in a stack trace.
 *
 * The coercion rules themselves live in ValueDecoder rather than being emitted here:
 * they are the same for every entity, and testing them once is better than generating
 * fifty copies of the same is_string check.
 */
final readonly class HydratorGenerator
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
        $class = $this->names->hydrator($entity);
        $namespace = $this->emitter->open($class);

        $entityClass = $this->names->entity($entity);

        $namespace->addUse(Runtime::HYDRATOR);
        $namespace->addUse(Runtime::EDGE_LOADER);
        $namespace->addUse(Runtime::RECORD);
        $namespace->addUse(Runtime::VALUE_DECODER);
        $namespace->addUse($entityClass);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(Runtime::HYDRATOR);
        $type->addComment(sprintf('Builds a %s from a stored row.', $entity->name));
        $type->addComment('');
        $type->addComment(sprintf('@implements Hydrator<%s>', $entity->name));

        $constructor = $type->addMethod('__construct');
        $constructor->addPromotedParameter('decode')
            ->setType(Runtime::VALUE_DECODER)
            ->setPrivate();

        foreach ($this->declaredTypes($entity) as $typeName) {
            $processor = $this->names->readProcessor($typeName);
            $namespace->addUse($processor);

            $constructor->addPromotedParameter(lcfirst($typeName) . 'Reader')
                ->setType($processor)
                ->setPrivate();
        }

        $arguments = $this->schema->hasEdges($entity) ? ['$record->id', '$edges'] : ['$record->id'];

        foreach ($entity->fields as $field) {
            $arguments[] = sprintf('$this->%s($record)', $field->name);
        }

        $hydrate = $type->addMethod('hydrate')
            ->setReturnType($entityClass)
            ->setBody(sprintf(
                "return %s::of(\n    %s,\n);",
                $this->emitter->shortName($entityClass),
                implode(",\n    ", $arguments),
            ));

        $hydrate->addParameter('record')->setType(Runtime::RECORD);
        $hydrate->addParameter('edges')->setType(Runtime::EDGE_LOADER);

        foreach ($entity->fields as $field) {
            $phpType = $this->types->forField($entity, $field);

            if (!in_array($phpType, self::SCALARS, true)) {
                $namespace->addUse($phpType);
            }

            $reader = $type->addMethod($field->name)
                ->setPrivate()
                ->setReturnType($phpType)
                ->setReturnNullable($field->nullable)
                ->setBody($this->body($entity, $field, $phpType));

            $reader->addParameter('record')->setType(Runtime::RECORD);
        }

        return $this->emitter->file($class, $namespace);
    }

    private function body(EntityDefinition $entity, FieldDefinition $field, string $phpType): string
    {
        $read = sprintf('$record->value(%s)', var_export($field->name, true));
        $label = var_export(sprintf('%s.%s', $entity->name, $field->name), true);

        $conversion = $this->conversion($field, $phpType, '$value', $label);

        if (!$field->nullable) {
            return sprintf("\$value = %s;\n\nreturn %s;", $read, $conversion);
        }

        // Null short-circuits on the way up as it does on the way down, so no
        // processor and no enum lookup ever sees it.
        return sprintf(
            "\$value = %s;\n\nreturn null === \$value ? null : %s;",
            $read,
            $conversion,
        );
    }

    private function conversion(
        FieldDefinition $field,
        string $phpType,
        string $value,
        string $label,
    ): string {
        $primitive = $field->type->primitive;

        if (null === $primitive) {
            $typeName = (string) $field->type->declaredType;
            $declared = $this->schema->type($typeName);
            $backing = $declared->primitive ?? Primitive::String;
            if (null !== $declared && $declared->isEnum()) {
                return $this->decode(Primitive::Enum, $value, $label, $phpType);
            }
            if (null === $declared || !$declared->hasProcessors) {
                return $this->decode($backing, $value, $label, $phpType);
            }

            $backing = in_array($backing, [Primitive::Json, Primitive::Datetime], true) ? Primitive::String : $backing;
            return sprintf(
                '$this->%sReader->read(%s)',
                lcfirst($typeName),
                $this->decode($backing, $value, $label, $phpType),
            );
        }

        return $this->decode($primitive, $value, $label, $phpType);
    }

    private function decode(Primitive $primitive, string $value, string $label, string $phpType): string
    {
        return match ($primitive) {
            Primitive::String, Primitive::Text => sprintf('$this->decode->string(%s, %s)', $value, $label),
            Primitive::Int => sprintf('$this->decode->int(%s, %s)', $value, $label),
            Primitive::Float => sprintf('$this->decode->float(%s, %s)', $value, $label),
            Primitive::Bool => sprintf('$this->decode->bool(%s, %s)', $value, $label),
            Primitive::Datetime => sprintf('$this->decode->datetime(%s, %s)', $value, $label),
            Primitive::Id => sprintf('$this->decode->id(%s, %s)', $value, $label),
            Primitive::Json => sprintf('$this->decode->json(%s, %s)', $value, $label),
            Primitive::Enum => sprintf(
                '$this->decode->enum(%s::class, %s, %s)',
                $this->emitter->shortName($phpType),
                $value,
                $label,
            ),
        };
    }

    /**
     * Declared types this entity's fields use, so their read processors are injected.
     *
     * @return list<string>
     */
    private function declaredTypes(EntityDefinition $entity): array
    {
        $types = [];

        foreach ($entity->fields as $field) {
            $name = $field->type->declaredType;

            if (null === $name) {
                continue;
            }

            $declared = $this->schema->type($name);

            // Enums are generated classes, not processor-backed value objects.
            if (null === $declared || $declared->isEnum() || !$declared->hasProcessors) {
                continue;
            }

            $types[$name] = true;
        }

        return array_keys($types);
    }
}
