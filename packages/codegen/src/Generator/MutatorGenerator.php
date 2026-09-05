<?php

declare(strict_types=1);

namespace PheFr\Codegen\Generator;

use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\Naming\Emitter;
use PheFr\Codegen\Naming\Names;
use PheFr\Codegen\Naming\TypeMapper;
use PheFr\Runtime\Mutation\MutationBuffer;
use PheFr\Schema\Ir\EntityDefinition;

/**
 * Emits the write model: a command buffer with one method per declared operation.
 *
 * Setters record intent rather than writing, so verification can run over the complete
 * pending state at commit and report every violation at once. Immutable fields get no
 * setter at all — write-once is enforced by the absence of a method, which no amount
 * of discipline can forget.
 *
 * Actions do not receive the buffer. They receive a narrow context generated from
 * their declared writes, so an action physically cannot touch a field the spec does
 * not say it touches.
 */
final readonly class MutatorGenerator
{
    public function __construct(
        private Names $names,
        private TypeMapper $types,
        private Emitter $emitter,
    ) {
    }

    public function generate(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->mutator($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(MutationBuffer::class);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->addComment(sprintf('Pending changes to a %s.', $entity->name));

        $constructor = $type->addMethod('__construct');
        $constructor->addPromotedParameter('buffer')
            ->setType(MutationBuffer::class)
            ->setPrivate()
            ->setReadOnly();

        foreach ($entity->actions as $action) {
            $handler = $this->names->actionHandler($entity, $action->name);
            $namespace->addUse($handler);

            $constructor->addPromotedParameter($action->name . 'Action')
                ->setType($handler)
                ->setPrivate()
                ->setReadOnly();
        }

        foreach ($entity->fields as $field) {
            if ($field->immutable) {
                continue;
            }

            $phpType = $this->types->forField($entity, $field);

            if (!in_array($phpType, ['string', 'int', 'float', 'bool', 'array'], true)) {
                $namespace->addUse($phpType);
            }

            $setter = $type->addMethod($this->names->setter($field->name))
                ->setReturnType('self')
                ->setBody(sprintf(
                    "\$this->buffer->set(%s, \$%s);\n\nreturn \$this;",
                    var_export($field->name, true),
                    $field->name,
                ));

            $setter->addParameter($field->name)
                ->setType($phpType)
                ->setNullable($field->nullable);
        }

        foreach ($entity->actions as $action) {
            $context = $this->names->actionContext($entity, $action->name);
            $namespace->addUse($context);

            $arguments = [];

            foreach ($action->arguments as $argument) {
                $argumentType = $this->types->forArgument($argument);

                if (!in_array($argumentType, ['string', 'int', 'float', 'bool', 'array'], true)) {
                    $namespace->addUse($argumentType);
                }

                $arguments[] = '$' . $argument->name;
            }

            $method = $type->addMethod($action->name)->setReturnType('void');

            foreach ($action->arguments as $argument) {
                $parameter = $method->addParameter($argument->name)
                    ->setType($this->types->forArgument($argument))
                    ->setNullable($argument->nullable);

                if ($argument->nullable) {
                    $parameter->setDefaultValue(null);
                }
            }

            $method->setBody(sprintf(
                '$this->%sAction->handle(new %s($this->buffer)%s);',
                $action->name,
                $this->emitter->shortName($context),
                [] === $arguments ? '' : ', ' . implode(', ', $arguments),
            ));

            if (null !== $action->description) {
                $method->addComment($action->description);
            }
        }

        return $this->emitter->file($class, $namespace);
    }
}
