<?php

declare(strict_types=1);

namespace Eleph\Codegen\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Naming\Emitter;
use Eleph\Codegen\Naming\Names;
use Eleph\Codegen\Naming\TypeMapper;
use Eleph\Runtime\Mutation\EdgeMutation;
use Eleph\Runtime\Mutation\MutationBuffer;
use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;

/**
 * Emits the two kinds of context: an action's narrow write surface, and an entity's
 * typed view of a pending mutation.
 */
final readonly class ContextGenerator
{
    private const SCALARS = ['string', 'int', 'float', 'bool', 'array'];

    public function __construct(
        private Names $names,
        private TypeMapper $types,
        private Emitter $emitter,
    ) {
    }

    /**
     * One class per action, exposing only its declared writes.
     *
     * This is what turns "blast radius" from a comment into something the compiler
     * checks: a method that is not generated cannot be called.
     *
     * @return list<GeneratedFile>
     */
    public function actionContexts(EntityDefinition $entity): array
    {
        $files = [];

        foreach ($entity->actions as $action) {
            $class = $this->names->actionContext($entity, $action->name);
            $namespace = $this->emitter->open($class);
            $namespace->addUse(MutationBuffer::class);

            $type = $namespace->addClass($this->emitter->shortName($class));
            $type->setFinal();
            $type->addComment(sprintf(
                'Everything %s::%s() is allowed to write, and nothing else.',
                $entity->name,
                $action->name,
            ));

            $constructor = $type->addMethod('__construct');
            $constructor->addPromotedParameter('buffer')
                ->setType(MutationBuffer::class)
                ->setPrivate()
                ->setReadOnly();

            foreach ($action->writes->fields as $name) {
                $field = $entity->field($name);

                if (null === $field) {
                    continue;
                }

                $phpType = $this->types->forField($entity, $field);

                if (!in_array($phpType, self::SCALARS, true)) {
                    $namespace->addUse($phpType);
                }

                $setter = $type->addMethod($this->names->setter($name))
                    ->setReturnType('self')
                    ->setBody(sprintf(
                        "\$this->buffer->set(%s, \$%s);\n\nreturn \$this;",
                        var_export($name, true),
                        $name,
                    ));

                $setter->addParameter($name)->setType($phpType)->setNullable($field->nullable);
            }

            if ([] !== $action->writes->edges) {
                $namespace->addUse(EdgeMutation::class);
            }

            foreach ($action->writes->edges as $name) {
                $type->addMethod($name)
                    ->setReturnType(EdgeMutation::class)
                    ->setBody(sprintf('return $this->buffer->edge(%s);', var_export($name, true)));
            }

            $this->emitter->namedConstructor($type, $constructor);

            $files[] = $this->emitter->file($class, $namespace);
        }

        return $files;
    }

    /**
     * An entity's mutation context, typed.
     *
     * Shared type processors take the generic MutationContext and its stringly-typed
     * accessors, because Money is used by many entities and cannot accept a per-entity
     * class. Field verifiers and triggers get this instead, where every accessor is
     * exact.
     */
    public function mutationContext(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->mutationContext($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(MutationContext::class);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(MutationContext::class);
        $type->addComment(sprintf('A pending %s mutation, with exact types.', $entity->name));

        $constructor = $type->addMethod('__construct');
        $constructor->addPromotedParameter('context')
            ->setType(MutationContext::class)
            ->setPrivate();

        foreach (['entity' => 'string', 'isCreate' => 'bool'] as $method => $returns) {
            $type->addMethod($method)
                ->setReturnType($returns)
                ->setBody(sprintf('return $this->context->%s();', $method));
        }

        foreach (['original', 'pending'] as $method) {
            $delegate = $type->addMethod($method)
                ->setReturnType('mixed')
                ->setBody(sprintf('return $this->context->%s($field);', $method));
            $delegate->addParameter('field')->setType('string');
        }

        $changed = $type->addMethod('isChanged')
            ->setReturnType('bool')
            ->setBody('return $this->context->isChanged($field);');
        $changed->addParameter('field')->setType('string');

        $type->addMethod('changes')
            ->setReturnType('array')
            ->setBody('return $this->context->changes();')
            ->addComment('@return array<string, mixed>');

        foreach ($entity->fields as $field) {
            $phpType = $this->types->forField($entity, $field);

            if (!in_array($phpType, self::SCALARS, true)) {
                $namespace->addUse($phpType);
            }

            foreach (['original', 'pending'] as $side) {
                $type->addMethod($side . ucfirst($field->name))
                    ->setReturnType($phpType)
                    // Always nullable: there is no original on create, and a pending
                    // value can legitimately be null.
                    ->setReturnNullable(true)
                    ->setBody($this->narrowingBody($side, $field, $phpType));
            }
        }

        $this->emitter->namedConstructor($type, $constructor);

        return $this->emitter->file($class, $namespace);
    }

    /**
     * The generic context returns mixed, so each typed accessor asserts its own type.
     *
     * assert() is the right tool: PHPStan narrows on it, and in production it compiles
     * away, so exact typing costs nothing at runtime.
     */
    private function narrowingBody(string $side, FieldDefinition $field, string $phpType): string
    {
        $check = match ($phpType) {
            'string' => 'is_string($value)',
            'int' => 'is_int($value)',
            'float' => 'is_float($value)',
            'bool' => 'is_bool($value)',
            'array' => 'is_array($value)',
            default => sprintf('$value instanceof %s', $this->emitter->shortName($phpType)),
        };

        return sprintf(
            "\$value = \$this->context->%s(%s);\nassert(null === \$value || %s);\n\nreturn \$value;",
            $side,
            var_export($field->name, true),
            $check,
        );
    }
}
