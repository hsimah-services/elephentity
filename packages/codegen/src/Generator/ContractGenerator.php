<?php

declare(strict_types=1);

namespace PheFr\Codegen\Generator;

use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;
use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\Naming\Emitter;
use PheFr\Codegen\Naming\Names;
use PheFr\Codegen\Naming\TypeMapper;
use PheFr\Runtime\Query\EntityQuery;
use PheFr\Runtime\Type\ReadProcessor;
use PheFr\Runtime\Type\WriteProcessor;
use PheFr\Runtime\Verification\Verification;
use PheFr\Schema\Ir\ArgumentDefinition;
use PheFr\Schema\Ir\Cardinality;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\Schema;

/**
 * Emits the interfaces the application must implement.
 *
 * This is where the framework's bargain sits. The spec declares the bespoke unit, the
 * generator emits an exactly-typed interface for it, and a human or agent writes the
 * class. Until one exists the application does not boot — so a declared query with no
 * implementation is a startup failure rather than a surprise in production.
 *
 * These interfaces deliberately extend nothing. PHP forbids narrowing a parameter type
 * in an implementation, so a common base declaring verify(mixed, MutationContext)
 * would make PostPriceVerifier::verify(Money, PostMutationContext) illegal. Generated
 * callers know the concrete type and call it directly, which keeps the typing exact.
 */
final readonly class ContractGenerator
{
    public function __construct(
        private Schema $schema,
        private Names $names,
        private TypeMapper $types,
        private Emitter $emitter,
    ) {
    }

    /**
     * @return list<GeneratedFile>
     */
    public function generate(EntityDefinition $entity): array
    {
        return [
            ...$this->queries($entity),
            ...$this->actions($entity),
            ...$this->triggers($entity),
            ...$this->verifiers($entity),
        ];
    }

    /**
     * Processors belong to a type rather than an entity, so they are emitted once.
     *
     * @return list<GeneratedFile>
     */
    public function processors(): array
    {
        $files = [];

        foreach ($this->schema->types as $type) {
            if (!$type->hasProcessors) {
                continue;
            }

            $stored = match ($type->primitive->value) {
                'int' => 'int',
                'float' => 'float',
                'bool' => 'bool',
                default => 'string',
            };

            $value = $this->names->valueClass($type->name);

            $readName = $this->names->readProcessor($type->name);
            $readNamespace = $this->emitter->open($readName);
            $readNamespace->addUse(ReadProcessor::class);
            $readNamespace->addUse($value);

            $read = $readNamespace->addInterface($this->emitter->shortName($readName));
            $read->addExtend(ReadProcessor::class);
            $read->addComment(sprintf('Turns a stored %s into a %s.', $stored, $type->name));
            $read->addComment('');
            $read->addComment(sprintf('@extends ReadProcessor<%s, %s>', $stored, $type->name));
            $read->addMethod('read')
                ->setPublic()
                ->setReturnType($value)
                ->addParameter('value')->setType('mixed');

            $files[] = $this->emitter->file($readName, $readNamespace);

            $writeName = $this->names->writeProcessor($type->name);
            $writeNamespace = $this->emitter->open($writeName);
            $writeNamespace->addUse(WriteProcessor::class);
            $writeNamespace->addUse($value);

            $write = $writeNamespace->addInterface($this->emitter->shortName($writeName));
            $write->addExtend(WriteProcessor::class);
            $write->addComment(sprintf('Checks a %s and turns it back into a stored %s.', $type->name, $stored));
            $write->addComment('');
            $write->addComment(sprintf('@extends WriteProcessor<%s, %s>', $stored, $type->name));
            $write->addMethod('write')
                ->setPublic()
                ->setReturnType($stored)
                ->addParameter('value')->setType('mixed');

            $files[] = $this->emitter->file($writeName, $writeNamespace);
        }

        return $files;
    }

    /**
     * @return list<GeneratedFile>
     */
    private function queries(EntityDefinition $entity): array
    {
        $files = [];

        foreach ($entity->queries as $query) {
            $name = $this->names->queryHandler($entity, $query->name);
            $namespace = $this->emitter->open($name);

            $interface = $namespace->addInterface($this->emitter->shortName($name));
            $interface->addComment($query->description ?? sprintf(
                'Backs %sFinder::%s().',
                $entity->name,
                $query->name,
            ));

            $method = $interface->addMethod('find')->setPublic();
            $this->addArguments($namespace, $method, $query->arguments);

            $returns = $this->schema->entity($query->returns->type);
            $returnClass = null === $returns ? null : $this->names->entity($returns);

            if (null === $returnClass) {
                continue;
            }

            $namespace->addUse($returnClass);

            if (Cardinality::One === $query->returns->cardinality) {
                $method->setReturnType($returnClass)->setReturnNullable(true);
            } else {
                $namespace->addUse(EntityQuery::class);
                $method->setReturnType(EntityQuery::class);
                $method->addComment(sprintf(
                    '@return EntityQuery<%s>',
                    $this->emitter->shortName($returnClass),
                ));
            }

            $files[] = $this->emitter->file($name, $namespace);
        }

        return $files;
    }

    /**
     * @return list<GeneratedFile>
     */
    private function actions(EntityDefinition $entity): array
    {
        $files = [];

        foreach ($entity->actions as $action) {
            $name = $this->names->actionHandler($entity, $action->name);
            $namespace = $this->emitter->open($name);

            $context = $this->names->actionContext($entity, $action->name);
            $namespace->addUse($context);

            $interface = $namespace->addInterface($this->emitter->shortName($name));
            $interface->addComment($action->description ?? sprintf(
                'Backs %sMutator::%s().',
                $entity->name,
                $action->name,
            ));
            $interface->addComment('');
            $interface->addComment(sprintf(
                'The context exposes only what %s declares it writes, so this cannot',
                $action->name,
            ));
            $interface->addComment('touch anything the spec does not say it touches.');

            $method = $interface->addMethod('handle')->setPublic()->setReturnType('void');
            $method->addParameter('context')->setType($context);
            $this->addArguments($namespace, $method, $action->arguments);

            $files[] = $this->emitter->file($name, $namespace);
        }

        return $files;
    }

    /**
     * @return list<GeneratedFile>
     */
    private function triggers(EntityDefinition $entity): array
    {
        $files = [];

        foreach ($entity->triggers as $trigger) {
            $name = $this->names->triggerHandler($entity, $trigger->name);
            $namespace = $this->emitter->open($name);

            $context = $this->names->mutationContext($entity);
            $namespace->addUse($context);

            $interface = $namespace->addInterface($this->emitter->shortName($name));
            $interface->addComment($trigger->description ?? sprintf(
                'Runs %s on %s.',
                $trigger->phase->value,
                implode(', ', array_map(static fn ($event) => $event->value, $trigger->events)),
            ));
            $interface->addComment('');
            $interface->addComment($trigger->phase->allowsMutation()
                ? 'postCommit: the transaction is closed, so writes here are a new unit of'
                : 'preCommit: inside the transaction and after the flush, so ids exist. Throw');
            $interface->addComment($trigger->phase->allowsMutation()
                ? 'work and are not atomic with the commit that caused them. A throw is logged.'
                : 'to abort the whole commit. Mutation is not permitted in this phase.');

            $method = $interface->addMethod('handle')->setPublic()->setReturnType('void');
            $method->addParameter('context')->setType($context);

            $files[] = $this->emitter->file($name, $namespace);
        }

        return $files;
    }

    /**
     * @return list<GeneratedFile>
     */
    private function verifiers(EntityDefinition $entity): array
    {
        $files = [];

        foreach ($entity->fields as $field) {
            if (!$field->verify) {
                continue;
            }

            $name = $this->names->fieldVerifier($entity, $field);
            $namespace = $this->emitter->open($name);

            $context = $this->names->mutationContext($entity);
            $valueType = $this->types->forField($entity, $field);

            $namespace->addUse($context);
            $namespace->addUse(Verification::class);

            if (!in_array($valueType, ['string', 'int', 'float', 'bool', 'array'], true)) {
                $namespace->addUse($valueType);
            }

            $interface = $namespace->addInterface($this->emitter->shortName($name));
            $interface->addComment(sprintf('Entity-specific rules for %s::%s.', $entity->name, $field->name));
            $interface->addComment('');
            $interface->addComment('Runs before the type processor, and both run even when this fails, so');
            $interface->addComment('violations from both tiers arrive together.');

            $method = $interface->addMethod('verify')->setPublic()->setReturnType(Verification::class);
            $method->addParameter('value')->setType($valueType);
            $method->addParameter('context')->setType($context);

            $files[] = $this->emitter->file($name, $namespace);
        }

        return $files;
    }

    /**
     * @param array<string, ArgumentDefinition> $arguments
     */
    private function addArguments(
        PhpNamespace $namespace,
        Method $method,
        array $arguments,
    ): void {
        foreach ($arguments as $argument) {
            $type = $this->types->forArgument($argument);

            if (!in_array($type, ['string', 'int', 'float', 'bool', 'array'], true)) {
                $namespace->addUse($type);
            }

            $parameter = $method->addParameter($argument->name)
                ->setType($type)
                ->setNullable($argument->nullable);

            if ($argument->nullable) {
                $parameter->setDefaultValue(null);
            }
        }
    }
}
