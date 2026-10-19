<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Runtime;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Emits the one class that tells the runtime what exists.
 *
 * Everything in it is a walk over the spec — which hydrator belongs to which entity,
 * what an edge points at, which field carries a declared type — so it is exactly the
 * work a generator should do once rather than a runtime should redo per request.
 *
 * It resolves through the application's container rather than taking every generated
 * service as a constructor argument, so adding an entity does not widen a signature.
 * The exception is mutators, which are built here: a mutator writes into one buffer
 * and every mutation needs its own.
 */
final readonly class CatalogueGenerator
{
    public function __construct(
        private Schema $schema,
        private Names $names,
        private Emitter $emitter,
    ) {
    }

    public function generate(): GeneratedFile
    {
        $class = $this->names->catalogue();
        $namespace = $this->emitter->open($class);

        foreach ([
            Runtime::ENTITY_CATALOGUE, Runtime::ENTITY_SIDE_EFFECTS, Runtime::ENTITY_VERIFIERS,
            Runtime::ENTITY_READ_POLICIES, Runtime::ENTITY_WRITE_POLICIES, Runtime::NO_POLICIES,
            Runtime::HYDRATOR, Runtime::MUTATION_BUFFER, Runtime::DELETION_RULE,
            ContainerInterface::class, RuntimeException::class,
        ] as $used) {
            $namespace->addUse($used);
        }

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(Runtime::ENTITY_CATALOGUE);
        $type->addComment('What the runtime knows about this project\'s entities.');

        $type->addMethod('__construct')
            ->addPromotedParameter('container')
            ->setType(ContainerInterface::class)
            ->setPrivate();

        $entities = array_keys($this->schema->entities);
        sort($entities);

        $type->addMethod('entities')
            ->setReturnType('array')
            ->setBody(sprintf('return %s;', $this->export($entities)))
            ->addComment('@return list<string>');

        $this->resolver($type, 'hydrator', Runtime::HYDRATOR, fn (EntityDefinition $e): string => $this->names->hydrator($e), $namespace);
        $this->resolver($type, 'verifiers', Runtime::ENTITY_VERIFIERS, fn (EntityDefinition $e): string => $this->names->verifiers($e), $namespace);
        $this->resolver($type, 'sideEffects', Runtime::ENTITY_SIDE_EFFECTS, fn (EntityDefinition $e): string => $this->names->sideEffects($e), $namespace);
        $this->policyResolver($type, 'readPolicies', Runtime::ENTITY_READ_POLICIES, false, $namespace);
        $this->policyResolver($type, 'writePolicies', Runtime::ENTITY_WRITE_POLICIES, true, $namespace);

        $this->addMap($type, 'edgeTargets', $this->edgeTargets(), '"Entity.edge" => target entity');
        $this->addMap($type, 'fieldTypes', $this->fieldTypes(), '"Entity.field" => declared type');

        $this->addPerEntityList($type, 'fieldNames', $this->fieldNames());
        $this->addPerEntityList($type, 'requiredFields', $this->requiredFields());
        $this->addPerEntityList($type, 'nonNullableFields', $this->nonNullableFields());
        $this->addPerEntityList($type, 'requiredEdges', $this->requiredEdges());
        $this->addPerEntityList($type, 'uniqueFields', $this->uniqueFields());
        $this->addManagedFields($type, $namespace);
        $this->addDeletionRules($type, $namespace);
        $this->addFinder($type, $namespace);
        $hasActions = $this->addMutatorFactory($type, $namespace);
        $this->addQueryArguments($type);
        $this->addActionArguments($type);
        $this->addDecodeActionArguments($type, $namespace);
        $this->addApply($type, $namespace);
        $this->addContracts($type);

        if ($hasActions) {
            $this->addResolve($type);
        }

        return $this->emitter->file($class, $namespace);
    }

    /**
     * @param callable(EntityDefinition): string $classFor
     */
    private function resolver(
        \Nette\PhpGenerator\ClassType $type,
        string $method,
        string $returns,
        callable $classFor,
        \Nette\PhpGenerator\PhpNamespace $namespace,
    ): void {
        $arms = [];

        foreach ($this->schema->entities as $entity) {
            $target = $classFor($entity);
            $namespace->addUse($target);

            $arms[] = sprintf(
                '    %s => $this->container->get(%s::class),',
                var_export($entity->name, true),
                $this->emitter->shortName($target),
            );
        }

        $body = sprintf(
            "\$service = match (\$entity) {\n%s\n    default => throw new RuntimeException(sprintf('No entity named \"%%s\".', \$entity)),\n};\n\nassert(\$service instanceof %s);\n\nreturn \$service;",
            implode("\n", $arms),
            $this->emitter->shortName($returns),
        );

        $resolved = $type->addMethod($method)->setReturnType($returns)->setBody($body);
        $resolved->addParameter('entity')->setType('string');

        if (Runtime::HYDRATOR === $returns) {
            $resolved->addComment('@return Hydrator<object>');
        }
    }

    /**
     * @return array<string, string>
     */
    private function edgeTargets(): array
    {
        $targets = [];

        foreach ($this->schema->entities as $entity) {
            foreach ($entity->edges as $edge) {
                $targets[$entity->name . '.' . $edge->name] = $edge->to;
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * @return array<string, string>
     */
    private function fieldTypes(): array
    {
        $types = [];

        foreach ($this->schema->entities as $entity) {
            foreach ($entity->fields as $field) {
                $declared = $field->type->declaredType;

                if (null !== $declared && true === $this->schema->type($declared)?->hasProcessors) {
                    $types[$entity->name . '.' . $field->name] = $declared;
                }
            }
        }

        ksort($types);

        return $types;
    }

    /**
     * @return array<string, list<string>>
     */
    private function fieldNames(): array
    {
        $names = [];

        foreach ($this->schema->entities as $entity) {
            $names[$entity->name] = array_keys($entity->fields);
        }

        return $names;
    }

    /** @return array<string, list<string>> */
    private function nonNullableFields(): array
    {
        $fields = [];
        foreach ($this->schema->entities as $entity) {
            $fields[$entity->name] = [];
            foreach ($entity->fields as $field) {
                if (!$field->nullable) {
                    $fields[$entity->name][] = $field->name;
                }
            }
        }
        return $fields;
    }

    /**
     * Fields a create must supply.
     *
     * A field with a default is left out: generated input seeds it on create, and
     * demanding one anyway would make `default:` unusable. Managed fields are left out
     * too — the framework stamps them before this is consulted.
     *
     * @return array<string, list<string>>
     */
    private function requiredFields(): array
    {
        $required = [];

        foreach ($this->schema->entities as $entity) {
            $names = [];

            foreach ($entity->fields as $field) {
                if ($field->required && !$field->hasDefault && null === $field->managed) {
                    $names[] = $field->name;
                }
            }

            $required[$entity->name] = $names;
        }

        return $required;
    }

    /**
     * To-one edges a create must attach.
     *
     * The schema compiler has already refused required: true on anything but a
     * cardinality: one edge, so nothing here re-checks that.
     *
     * @return array<string, list<string>>
     */
    private function requiredEdges(): array
    {
        $required = [];

        foreach ($this->schema->entities as $entity) {
            $names = [];

            foreach ($entity->edges as $edge) {
                if ($edge->required) {
                    $names[] = $edge->name;
                }
            }

            $required[$entity->name] = $names;
        }

        return $required;
    }

    /**
     * @return array<string, list<string>>
     */
    private function uniqueFields(): array
    {
        $unique = [];

        foreach ($this->schema->entities as $entity) {
            $names = [];

            foreach ($entity->fields as $field) {
                if ($field->unique) {
                    $names[] = $field->name;
                }
            }

            $unique[$entity->name] = $names;
        }

        return $unique;
    }

    private function addManagedFields(\Nette\PhpGenerator\ClassType $type, \Nette\PhpGenerator\PhpNamespace $namespace): void
    {
        $lines = [];

        foreach ($this->schema->entities as $entity) {
            foreach ($entity->fields as $field) {
                if (null === $field->managed) {
                    continue;
                }

                $lines[] = sprintf(
                    '    %s => Managed::%s,',
                    var_export($entity->name . '.' . $field->name, true),
                    ucfirst($field->managed->value),
                );
            }
        }

        if ([] !== $lines) {
            $namespace->addUse(Runtime::MANAGED);
        }

        sort($lines);

        $type->addMethod('managedFields')
            ->setReturnType('array')
            ->setBody([] === $lines ? 'return [];' : sprintf("return [\n%s\n];", implode("\n", $lines)))
            ->addComment('@return array<string, Managed> "Entity.field" => policy');
    }

    private function addDeletionRules(\Nette\PhpGenerator\ClassType $type, \Nette\PhpGenerator\PhpNamespace $namespace): void
    {
        $arms = [];

        foreach ($this->schema->entities as $entity) {
            $deleter = $this->names->deleter($entity);
            $namespace->addUse($deleter);

            $arms[] = sprintf(
                '    %s => %s::rules(),',
                var_export($entity->name, true),
                $this->emitter->shortName($deleter),
            );
        }

        $method = $type->addMethod('deletionRules')
            ->setReturnType('array')
            ->setBody(sprintf("return match (\$entity) {\n%s\n    default => [],\n};", implode("\n", $arms)))
            ->addComment('@return list<DeletionRule>');

        $method->addParameter('entity')->setType('string');
    }

    private function addFinder(\Nette\PhpGenerator\ClassType $type, \Nette\PhpGenerator\PhpNamespace $namespace): void
    {
        $arms = [];

        foreach ($this->schema->entities as $entity) {
            if ([] === $entity->queries) {
                continue;
            }

            $finder = $this->names->finder($entity);
            $namespace->addUse($finder);

            $arms[] = sprintf(
                '    %s => $this->container->get(%s::class),',
                var_export($entity->name, true),
                $this->emitter->shortName($finder),
            );
        }

        $body = sprintf(
            "\$finder = match (\$entity) {\n%s\n    default => throw new RuntimeException(sprintf('%%s declares no queries.', \$entity)),\n};\n\nassert(is_object(\$finder));\n\nreturn \$finder;",
            [] === $arms ? '' : implode("\n", $arms),
        );

        $type->addMethod('finder')->setReturnType('object')->setBody($body)
            ->addParameter('entity')->setType('string');
    }

    private function addMutatorFactory(\Nette\PhpGenerator\ClassType $type, \Nette\PhpGenerator\PhpNamespace $namespace): bool
    {
        $arms = [];
        $hasActions = false;

        foreach ($this->schema->entities as $entity) {
            $mutator = $this->names->mutator($entity);
            $namespace->addUse($mutator);

            $handlers = ['$buffer'];

            foreach ($entity->actions as $action) {
                $handler = $this->names->actionHandler($entity, $action->name);
                $namespace->addUse($handler);
                $handlers[] = sprintf('$this->resolve(%s::class)', $this->emitter->shortName($handler));
                $hasActions = true;
            }

            $arms[] = sprintf(
                '    %s => new %s(%s),',
                var_export($entity->name, true),
                $this->emitter->shortName($mutator),
                implode(', ', $handlers),
            );
        }

        $method = $type->addMethod('mutatorFor')
            ->setReturnType('object')
            ->setBody(sprintf(
                "return match (\$entity) {\n%s\n    default => throw new RuntimeException(sprintf('No entity named \"%%s\".', \$entity)),\n};",
                implode("\n", $arms),
            ));

        $method->addParameter('entity')->setType('string');
        $method->addParameter('buffer')->setType(Runtime::MUTATION_BUFFER);

        return $hasActions;
    }

    /**
     * Resolves a container value into the type its caller actually needs.
     *
     * `ContainerInterface::get()` returns `mixed`, so every call site that hands the
     * result straight to a typed parameter needs an assert in between — this is the one
     * place that does it, generic over the class asked for, rather than repeating the
     * assert at every action handler a mutator takes.
     */
    private function addResolve(\Nette\PhpGenerator\ClassType $type): void
    {
        $method = $type->addMethod('resolve')
            ->setReturnType('object')
            ->setBody("\$service = \$this->container->get(\$class);\n\nassert(\$service instanceof \$class);\n\nreturn \$service;")
            ->addComment('@template T of object')
            ->addComment('@param class-string<T> $class')
            ->addComment('@return T')
            ->setPrivate();

        $method->addParameter('class')->setType('string');
    }

    private function addQueryArguments(\Nette\PhpGenerator\ClassType $type): void
    {
        $arguments = [];

        foreach ($this->schema->entities as $entity) {
            foreach ($entity->queries as $query) {
                $arguments[$entity->name . '.' . $query->name] = array_keys($query->arguments);
            }
        }

        ksort($arguments);

        $lines = [];

        foreach ($arguments as $key => $names) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), $this->export($names));
        }

        $method = $type->addMethod('queryArguments')
            ->setReturnType('array')
            ->setBody(sprintf(
                "return match (\$entity . '.' . \$query) {\n%s\n    default => [],\n};",
                implode("\n", $lines),
            ))
            ->addComment('@return list<string>');

        $method->addParameter('entity')->setType('string');
        $method->addParameter('query')->setType('string');
    }

    private function addActionArguments(\Nette\PhpGenerator\ClassType $type): void
    {
        $arguments = [];
        foreach ($this->schema->entities as $entity) {
            foreach ($entity->actions as $action) {
                $arguments[$entity->name . '.' . $action->name] = array_keys($action->arguments);
            }
        }
        ksort($arguments);
        $lines = [];
        foreach ($arguments as $key => $names) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), $this->export($names));
        }
        $method = $type->addMethod('actionArguments')->setReturnType('array')->setBody(sprintf(
            "return match (\$entity . '.' . \$action) {\n%s\n    default => [],\n};",
            implode("\n", $lines),
        ))->addComment('@return list<string>');
        $method->addParameter('entity')->setType('string');
        $method->addParameter('action')->setType('string');
    }

    private function addDecodeActionArguments(\Nette\PhpGenerator\ClassType $type, \Nette\PhpGenerator\PhpNamespace $namespace): void
    {
        $arms = [];
        foreach ($this->schema->entities as $entity) {
            $input = $this->names->input($entity);
            $namespace->addUse($input);

            $method = $type->addMethod('decode' . $this->emitter->shortName($input) . 'ActionArguments')
                ->setPrivate()
                ->setReturnType('array')
                ->setBody(sprintf(
                    "\$input = \$this->container->get(%s::class);\n\nassert(\$input instanceof %s);\n\nreturn \$input->decodeAction(\$action, \$args);",
                    $this->emitter->shortName($input),
                    $this->emitter->shortName($input),
                ))
                ->addComment('@param array<string, mixed> $args')
                ->addComment('@return array<string, mixed>');
            $method->addParameter('action')->setType('string');
            $method->addParameter('args')->setType('array');

            $arms[] = sprintf(
                '    %s => $this->%s($action, $args),',
                var_export($entity->name, true),
                $method->getName(),
            );
        }
        $method = $type->addMethod('decodeActionArguments')
            ->setReturnType('array')
            ->setBody(sprintf(
                "return match (\$entity) {\n%s\n    default => throw new RuntimeException(sprintf('No entity named \"%%s\".', \$entity)),\n};",
                implode("\n", $arms),
            ))
            ->addComment('@param array<string, mixed> $args')
            ->addComment('@return array<string, mixed>');
        $method->addParameter('entity')->setType('string');
        $method->addParameter('action')->setType('string');
        $method->addParameter('args')->setType('array');
    }

    private function policyResolver(\Nette\PhpGenerator\ClassType $type, string $method, string $returns, bool $write, \Nette\PhpGenerator\PhpNamespace $namespace): void
    {
        $arms = [];
        foreach ($this->schema->entities as $entity) {
            $policies = $write ? $entity->writePolicies : $entity->readPolicies;
            if ([] === $policies) {
                $arms[] = sprintf('    %s => new NoPolicies(),', var_export($entity->name, true));
            } else {
                $target = $write ? $this->names->writePolicies($entity) : $this->names->readPolicies($entity);
                $namespace->addUse($target);
                $resolver = $type->addMethod($method . $this->emitter->shortName($target))
                    ->setPrivate()
                    ->setReturnType($returns)
                    ->setBody(sprintf(
                        "\$policies = \$this->container->get(%s::class);\n\nassert(\$policies instanceof %s);\n\nreturn \$policies;",
                        $this->emitter->shortName($target),
                        $this->emitter->shortName($target),
                    ));

                $arms[] = sprintf(
                    '    %s => $this->%s(),',
                    var_export($entity->name, true),
                    $resolver->getName(),
                );
            }
        }
        $resolved = $type->addMethod($method)->setReturnType($returns)->setBody(sprintf(
            "return match (\$entity) {\n%s\n    default => throw new RuntimeException(sprintf('No entity named \"%%s\".', \$entity)),\n};",
            implode("\n", $arms),
        ));
        $resolved->addParameter('entity')->setType('string');
    }

    private function addApply(\Nette\PhpGenerator\ClassType $type, \Nette\PhpGenerator\PhpNamespace $namespace): void
    {
        $arms = [];

        foreach ($this->schema->entities as $entity) {
            $input = $this->names->input($entity);
            $namespace->addUse($input);

            $arms[] = sprintf(
                '    %s => $this->container->get(%s::class),',
                var_export($entity->name, true),
                $this->emitter->shortName($input),
            );
        }

        $method = $type->addMethod('apply')
            ->setReturnType('void')
            ->setBody(sprintf(
                "\$applier = match (\$entity) {\n%s\n    default => throw new RuntimeException(sprintf('No entity named \"%%s\".', \$entity)),\n};\n\nassert(is_object(\$applier) && method_exists(\$applier, 'apply'));\n\n\$applier->apply(\$buffer, \$input);",
                implode("\n", $arms),
            ))
            ->addComment('@param array<string, mixed> $input');

        $method->addParameter('entity')->setType('string');
        $method->addParameter('buffer')->setType(Runtime::MUTATION_BUFFER);
        $method->addParameter('input')->setType('array');
    }

    private function addContracts(\Nette\PhpGenerator\ClassType $type): void
    {
        $contracts = [];

        foreach ($this->schema->entities as $entity) {
            foreach ($entity->queries as $query) {
                $contracts[] = $this->names->queryHandler($entity, $query->name);
            }

            foreach ($entity->actions as $action) {
                $contracts[] = $this->names->actionHandler($entity, $action->name);
            }

            foreach ($entity->sideEffects as $sideEffect) {
                $contracts[] = $this->names->sideEffectHandler($entity, $sideEffect->name);
            }

            foreach ($entity->fields as $field) {
                if ($field->verify) {
                    $contracts[] = $this->names->fieldVerifier($entity, $field);
                }
            }
            foreach ($entity->readPolicies as $policy) {
                if (!$policy->declaredIn()->isPattern()) {
                    $contracts[] = $this->names->readPolicyHandler($entity, $policy->name);
                }
            }
            foreach ($entity->writePolicies as $policy) {
                if (!$policy->declaredIn()->isPattern()) {
                    $contracts[] = $this->names->writePolicyHandler($entity, $policy->name);
                }
            }
        }

        foreach ($this->schema->patterns as $pattern) {
            foreach ($pattern->readPolicies as $policy) {
                $contracts[] = $this->names->patternReadPolicyHandler($pattern->name, $policy->name);
            }
            foreach ($pattern->writePolicies as $policy) {
                $contracts[] = $this->names->patternWritePolicyHandler($pattern->name, $policy->name);
            }
        }

        foreach ($this->schema->types as $declared) {
            if ($declared->hasProcessors) {
                $contracts[] = $this->names->readProcessor($declared->name);
                $contracts[] = $this->names->writeProcessor($declared->name);
            }
        }

        $contracts = array_values(array_unique($contracts));
        sort($contracts);

        $type->addMethod('contracts')
            ->setReturnType('array')
            ->setBody(sprintf('return %s;', $this->export($contracts)))
            ->addComment('Everything the application must implement before it can start.')
            ->addComment('')
            ->addComment('@return list<string>');
    }

    /**
     * @param array<string, string> $map
     */
    private function addMap(\Nette\PhpGenerator\ClassType $type, string $method, array $map, string $shape): void
    {
        $lines = [];

        foreach ($map as $key => $value) {
            $lines[] = sprintf('    %s => %s,', var_export($key, true), var_export($value, true));
        }

        $type->addMethod($method)
            ->setReturnType('array')
            ->setBody([] === $lines ? 'return [];' : sprintf("return [\n%s\n];", implode("\n", $lines)))
            ->addComment(sprintf('@return array<string, string> %s', $shape));
    }

    /**
     * @param array<string, list<string>> $map
     */
    private function addPerEntityList(\Nette\PhpGenerator\ClassType $type, string $method, array $map): void
    {
        $lines = [];

        foreach ($map as $entity => $values) {
            $lines[] = sprintf('    %s => %s,', var_export($entity, true), $this->export($values));
        }

        $added = $type->addMethod($method)
            ->setReturnType('array')
            ->setBody(sprintf("return match (\$entity) {\n%s\n    default => [],\n};", implode("\n", $lines)))
            ->addComment('@return list<string>');

        $added->addParameter('entity')->setType('string');
    }

    /**
     * @param list<string> $values
     */
    private function export(array $values): string
    {
        return [] === $values
            ? '[]'
            : '[' . implode(', ', array_map(static fn (string $v): string => var_export($v, true), $values)) . ']';
    }
}
