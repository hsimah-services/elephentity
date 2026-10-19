<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Closure;
use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Runtime;
use Nette\PhpGenerator\PhpNamespace;
use Psr\Container\ContainerInterface;

/**
 * Emits every generated class's container factory, so an application never hand-writes one.
 *
 * A hydrator, input applier, trigger bridge, verifier bridge or finder has exactly one
 * legal constructor call once the spec is known: a ValueDecoder, plus
 * `$container->get({Contract}::class)` for whichever type processors, triggers,
 * verifiers or queries this entity actually declares. None of that is a choice a
 * human makes — it is the same walk `CatalogueGenerator` already does to build
 * `contracts()` — so typing it by hand scales only until someone copies the wrong
 * four lines for the one entity whose field takes a second constructor argument.
 *
 * The hand-written `Contract` bindings are deliberately not here: they are what the
 * application owes the spec, `BootCheck` names them by walking `contracts()`, and a
 * factory for a class that does not exist yet would be nothing to bind.
 *
 * Returned as a `class-string => Closure` map rather than code that calls a
 * container, so this stays as container-agnostic as the rest of the generated tree —
 * PSR-11's `ContainerInterface` is the only shape assumed. A project feeds it in with
 * `foreach (Wiring::registrations() as $id => $factory) { $container->set($id,
 * $factory); }` or equivalent.
 */
final readonly class WiringGenerator
{
    public function __construct(
        private Schema $schema,
        private Names $names,
        private Emitter $emitter,
    ) {
    }

    public function generate(): GeneratedFile
    {
        $class = $this->names->wiring();
        $namespace = $this->emitter->open($class);
        $namespace->addUse(ContainerInterface::class);
        $namespace->addUse(Closure::class);
        $namespace->addUse(Runtime::VALUE_DECODER);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->addComment('Every generated class this project\'s container must be able to build.');

        $entries = [];

        foreach ($this->schema->entities as $entity) {
            $entries[] = $this->hydratorEntry($entity, $namespace);
            $entries[] = $this->inputEntry($entity, $namespace);
            $entries[] = $this->sideEffectsEntry($entity, $namespace);
            $entries[] = $this->verifiersEntry($entity, $namespace);
            $entries[] = $this->policiesEntry($entity, false, $namespace);
            $entries[] = $this->policiesEntry($entity, true, $namespace);

            $finder = $this->finderEntry($entity, $namespace);

            if (null !== $finder) {
                $entries[] = $finder;
            }
        }

        $type->addMethod('registrations')
            ->setStatic()
            ->setReturnType('array')
            ->setBody(sprintf("return [\n%s\n];", implode("\n", $entries)))
            ->addComment('The hand-written Contract bindings are not here: BootCheck enforces those')
            ->addComment('separately, against contracts() on the catalogue.')
            ->addComment('')
            ->addComment('@return array<class-string, Closure(ContainerInterface): object>');

        if ([] !== $this->schema->entities) {
            $this->addResolve($type);
        }

        return $this->emitter->file($class, $namespace);
    }

    /**
     * Narrows what `ContainerInterface::get()` hands back, the same way and for the
     * same reason as `CatalogueGenerator::resolve()`: PSR-11 says `mixed`, and every
     * arm above hands the result straight to a strictly-typed constructor parameter.
     * One generic assert here is what makes that pass level max instead of every arm
     * repeating it.
     */
    private function addResolve(\Nette\PhpGenerator\ClassType $type): void
    {
        $method = $type->addMethod('resolve')
            ->setStatic()
            ->setReturnType('object')
            ->setBody("\$service = \$c->get(\$class);\n\nassert(\$service instanceof \$class);\n\nreturn \$service;")
            ->addComment('@template T of object')
            ->addComment('@param class-string<T> $class')
            ->addComment('@return T')
            ->setPrivate();

        $method->addParameter('c')->setType(ContainerInterface::class);
        $method->addParameter('class')->setType('string');
    }

    private function hydratorEntry(EntityDefinition $entity, PhpNamespace $namespace): string
    {
        $args = [$this->decodeArg()];

        foreach ($this->hydratorDeclaredTypes($entity) as $typeName) {
            $args[] = $this->processorArg($typeName, $namespace);
        }

        return $this->arm($this->names->hydrator($entity), $args, $namespace);
    }

    private function inputEntry(EntityDefinition $entity, PhpNamespace $namespace): string
    {
        $args = [$this->decodeArg()];

        foreach ($this->inputDeclaredTypes($entity) as $typeName) {
            $args[] = $this->processorArg($typeName, $namespace);
        }

        return $this->arm($this->names->input($entity), $args, $namespace);
    }

    private function sideEffectsEntry(EntityDefinition $entity, PhpNamespace $namespace): string
    {
        $args = [];

        foreach ($entity->sideEffects as $sideEffect) {
            $args[] = $this->contractArg($this->names->sideEffectHandler($entity, $sideEffect->name), $namespace);
        }

        return $this->arm($this->names->sideEffects($entity), $args, $namespace);
    }

    private function verifiersEntry(EntityDefinition $entity, PhpNamespace $namespace): string
    {
        $args = [];

        foreach ($entity->fields as $field) {
            if (!$field->verify) {
                continue;
            }

            $args[] = $this->contractArg($this->names->fieldVerifier($entity, $field), $namespace);
        }

        return $this->arm($this->names->verifiers($entity), $args, $namespace);
    }

    private function policiesEntry(EntityDefinition $entity, bool $write, PhpNamespace $namespace): string
    {
        $dispatcher = $write ? $this->names->writePolicies($entity) : $this->names->readPolicies($entity);
        $args = [];
        $policies = $write ? $entity->writePolicies : $entity->readPolicies;
        foreach ($policies as $policy) {
            $handler = $policy->declaredIn()->isPattern()
                ? ($write
                    ? $this->names->patternWritePolicyHandler((string) $policy->declaredIn()->pattern, $policy->name)
                    : $this->names->patternReadPolicyHandler((string) $policy->declaredIn()->pattern, $policy->name))
                : ($write
                    ? $this->names->writePolicyHandler($entity, $policy->name)
                    : $this->names->readPolicyHandler($entity, $policy->name));
            $args[] = $this->contractArg($handler, $namespace);
        }

        return $this->arm($dispatcher, $args, $namespace);
    }

    private function finderEntry(EntityDefinition $entity, PhpNamespace $namespace): ?string
    {
        if ([] === $entity->queries) {
            return null;
        }

        $args = [];

        foreach ($entity->queries as $query) {
            $args[] = $this->contractArg($this->names->queryHandler($entity, $query->name), $namespace);
        }

        return $this->arm($this->names->finder($entity), $args, $namespace);
    }

    private function decodeArg(): string
    {
        return sprintf('self::resolve($c, %s::class)', $this->emitter->shortName(Runtime::VALUE_DECODER));
    }

    private function processorArg(string $typeName, PhpNamespace $namespace): string
    {
        return $this->contractArg($this->names->readProcessor($typeName), $namespace);
    }

    private function contractArg(string $class, PhpNamespace $namespace): string
    {
        $namespace->addUse($class);

        return sprintf('self::resolve($c, %s::class)', $this->emitter->shortName($class));
    }

    /**
     * @param list<string> $args
     */
    private function arm(string $class, array $args, PhpNamespace $namespace): string
    {
        $namespace->addUse($class);
        $short = $this->emitter->shortName($class);

        return sprintf(
            '    %s::class => static fn (ContainerInterface $c): object => new %s(%s),',
            $short,
            $short,
            implode(', ', $args),
        );
    }

    /**
     * Mirrors HydratorGenerator::declaredTypes() exactly: this entry has to match the
     * constructor the hydrator generator actually emitted, not a derived approximation
     * of it.
     *
     * @return list<string>
     */
    private function hydratorDeclaredTypes(EntityDefinition $entity): array
    {
        $types = [];

        foreach ($entity->fields as $field) {
            $name = $field->type->declaredType;

            if (null === $name) {
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

    /**
     * Mirrors InputGenerator::declaredTypes() exactly: managed fields are excluded
     * there because the application never supplies them, so a reader for one would
     * never be built — and this list has to match that constructor too.
     *
     * @return list<string>
     */
    private function inputDeclaredTypes(EntityDefinition $entity): array
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
