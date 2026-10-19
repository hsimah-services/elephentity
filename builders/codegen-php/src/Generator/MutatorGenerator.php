<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\Cardinality;
use Eleph\Gen\Php\Ir\EdgeDefinition;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Naming\TypeMapper;
use Eleph\Gen\Php\Runtime;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;

/**
 * Emits the write model: a command buffer with one method per declared operation.
 *
 * Setters record intent rather than writing, so verification can run over the complete
 * pending state at commit and report every violation at once. Immutable fields get no
 * setter at all — write-once is enforced by the absence of a method, which no amount
 * of discipline can forget — and neither do managed fields, which nobody sets because
 * the framework stamps them.
 *
 * Edges are writable through the same buffer, and their shape mirrors the read model:
 * a to-one edge gets `setItem(?EntityId)`, because a signature that cannot express two
 * targets is the cheapest possible enforcement of `cardinality: one`; a to-many edge
 * gets `tags()` returning the same EdgeMutation an action context exposes, so add,
 * remove and replace all exist without inventing three method names per edge.
 *
 * Actions do not receive the buffer. They receive a narrow context generated from
 * their declared writes, so an action physically cannot touch a field the spec does
 * not say it touches.
 */
final readonly class MutatorGenerator
{
    public function __construct(
        private Schema $schema,
        private Names $names,
        private TypeMapper $types,
        private Emitter $emitter,
    ) {
    }

    public function generate(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->mutator($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(Runtime::MUTATION_BUFFER);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->addComment(sprintf('Pending changes to a %s.', $entity->name));

        foreach ($entity->appliedPatterns as $patternName) {
            if (null === $this->schema->pattern($patternName)) {
                continue;
            }

            $namespace->addUse($this->names->patternMutatorTrait($patternName));
            $type->addTrait($this->names->patternMutatorTrait($patternName));
        }

        $constructor = $type->addMethod('__construct');
        $constructor->addPromotedParameter('buffer')
            ->setType(Runtime::MUTATION_BUFFER)
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
            // Write-once and machine-owned both come out as an absent method. Nothing
            // can forget a setter that was never emitted.
            if ($field->immutable || null !== $field->managed) {
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

        foreach ($entity->edges as $edge) {
            $this->addEdge($namespace, $type, $edge);
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
                '$this->%sAction->handle(%s::of($this->buffer)%s);',
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

    /**
     * The write side of one edge.
     *
     * Reading an edge worked and writing one had no generated path at all: an `item`
     * key handed to the gateway was silently dropped, and the row landed with a null
     * foreign key and no error. Both shapes take identifiers rather than entities, so
     * a commit can link a row that does not exist yet.
     */
    private function addEdge(PhpNamespace $namespace, ClassType $type, EdgeDefinition $edge): void
    {
        if (Cardinality::One === $edge->cardinality) {
            $namespace->addUse(Runtime::IDENTIFIER);

            $setter = $type->addMethod($this->names->setter($edge->name))
                ->setReturnType('self')
                ->setBody(sprintf(
                    "\$this->buffer->edge(%s)->set(null === \$%s ? [] : [\$%s]);\n\nreturn \$this;",
                    var_export($edge->name, true),
                    $edge->name,
                    $edge->name,
                ));

            $setter->addParameter($edge->name)->setType(Runtime::IDENTIFIER)->setNullable(true);
            $setter->addComment(sprintf('Point this at one %s, or at nothing.', $edge->to));

            return;
        }

        $namespace->addUse(Runtime::EDGE_MUTATION);

        $method = $type->addMethod($edge->name)
            ->setReturnType(Runtime::EDGE_MUTATION)
            ->setBody(sprintf('return $this->buffer->edge(%s);', var_export($edge->name, true)));

        $method->addComment(sprintf('Add, remove or replace the %s this links to.', $edge->to));
    }
}
