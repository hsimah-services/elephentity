<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\TerminalRule;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Naming\TypeMapper;
use Eleph\Gen\Php\Runtime;

/**
 * Emits the adapters between the runtime and the application's exactly-typed classes.
 *
 * The runtime has to call a verifier and a trigger polymorphically, but the interfaces
 * the application implements take concrete types — `verify(Money, PostMutationContext)`
 * — and PHP forbids narrowing a parameter, so no shared base could ever declare them.
 *
 * The way out is generated code, which is allowed to know both sides: these classes
 * take `mixed`, narrow it with an assert, wrap the context, and dispatch. The user's
 * interface stays exactly typed and the runtime stays generic, with the bridge between
 * them written by a machine rather than by hand fifty times.
 */
final readonly class BridgeGenerator
{
    private const SCALARS = ['string', 'int', 'float', 'bool', 'array'];

    public function __construct(
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
            $this->verifiers($entity),
            $this->sideEffects($entity),
            $this->readPolicies($entity),
            $this->writePolicies($entity),
        ];
    }

    private function readPolicies(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->readPolicies($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(Runtime::ENTITY_READ_POLICIES);
        $namespace->addUse(Runtime::POLICY_DECISION);
        $namespace->addUse(Runtime::POLICY_OUTCOME);
        $namespace->addUse(Runtime::VIEWER);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal()->setReadOnly()->addImplement(Runtime::ENTITY_READ_POLICIES);
        $constructor = $type->addMethod('__construct');
        $lines = [];
        $policies = array_values($entity->readPolicies);

        foreach ($policies as $policy) {
            $handler = $policy->declaredIn()->isPattern()
                ? $this->names->patternReadPolicyHandler((string) $policy->declaredIn()->pattern, $policy->name)
                : $this->names->readPolicyHandler($entity, $policy->name);
            $namespace->addUse($handler);
            $constructor->addPromotedParameter($policy->name . 'Policy')->setType($handler)->setPrivate();
        }

        $type->addMethod('isEmpty')->setReturnType('bool')->setBody([] === $policies ? 'return true;' : 'return false;');
        $dispatch = $type->addMethod('decide')->setReturnType(Runtime::POLICY_DECISION);
        $dispatch->addParameter('entity')->setType('object');
        $dispatch->addParameter('viewer')->setType(Runtime::VIEWER);
        if ([] === $policies) {
            $dispatch->setBody('return PolicyDecision::allow();');
        } else {
            $entityType = $this->names->entity($entity);
            $namespace->addUse($entityType);
            $lines[] = sprintf('assert($entity instanceof %s);', $this->emitter->shortName($entityType));
            foreach ($policies as $policy) {
                $lines[] = sprintf('$decision = $this->%sPolicy->decide($entity, $viewer);', $policy->name);
                $lines[] = 'if (PolicyOutcome::Skip !== $decision->outcome) {';
                $lines[] = sprintf('    return $%s->withPolicy(%s);', 'decision', var_export($policy->name, true));
                $lines[] = '}';
                $lines[] = '';
            }
            $terminal = TerminalRule::Allow === $entity->terminalRead
                ? 'allow'
                : 'deny';
            $lines[] = sprintf("return PolicyDecision::%s(%s)->withPolicy('terminal');", $terminal, var_export('deny' === $terminal ? 'No policy allowed this read.' : '', true));
            $dispatch->setBody(implode("\n", $lines));
        }

        return $this->emitter->file($class, $namespace);
    }

    private function writePolicies(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->writePolicies($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(Runtime::ENTITY_WRITE_POLICIES);
        $namespace->addUse(Runtime::POLICY_DECISION);
        $namespace->addUse(Runtime::POLICY_OUTCOME);
        $namespace->addUse(Runtime::VIEWER);
        $namespace->addUse(Runtime::WRITE_CONTEXT);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal()->setReadOnly()->addImplement(Runtime::ENTITY_WRITE_POLICIES);
        $constructor = $type->addMethod('__construct');
        $policies = array_values($entity->writePolicies);
        foreach ($policies as $policy) {
            $handler = $policy->declaredIn()->isPattern()
                ? $this->names->patternWritePolicyHandler((string) $policy->declaredIn()->pattern, $policy->name)
                : $this->names->writePolicyHandler($entity, $policy->name);
            $namespace->addUse($handler);
            $constructor->addPromotedParameter($policy->name . 'Policy')->setType($handler)->setPrivate();
        }
        $type->addMethod('isEmpty')->setReturnType('bool')->setBody([] === $policies ? 'return true;' : 'return false;');
        $dispatch = $type->addMethod('decide')->setReturnType(Runtime::POLICY_DECISION);
        $dispatch->addParameter('entity')->setType('object')->setNullable(true);
        $dispatch->addParameter('context')->setType(Runtime::WRITE_CONTEXT);
        $dispatch->addParameter('viewer')->setType(Runtime::VIEWER);
        if ([] === $policies) {
            $dispatch->setBody('return PolicyDecision::allow();');
        } else {
            $entityType = $this->names->entity($entity);
            $namespace->addUse($entityType);
            $lines = [sprintf('assert(null === $entity || $entity instanceof %s);', $this->emitter->shortName($entityType))];
            foreach ($policies as $policy) {
                $argumentContext = $policy->declaredIn()->isPattern()
                    ? '$context'
                    : sprintf('%s::of($context)', $this->emitter->shortName($this->names->writeContext($entity)));
                if (!$policy->declaredIn()->isPattern()) {
                    $namespace->addUse($this->names->writeContext($entity));
                }
                $lines[] = sprintf('$decision = $this->%sPolicy->decide($entity, %s, $viewer);', $policy->name, $argumentContext);
                $lines[] = 'if (PolicyOutcome::Skip !== $decision->outcome) {';
                $lines[] = sprintf('    return $%s->withPolicy(%s);', 'decision', var_export($policy->name, true));
                $lines[] = '}';
                $lines[] = '';
            }
            $terminal = TerminalRule::Allow === $entity->terminalWrite ? 'allow' : 'deny';
            $lines[] = sprintf("return PolicyDecision::%s(%s)->withPolicy('terminal');", $terminal, var_export('deny' === $terminal ? 'No policy allowed this write.' : '', true));
            $dispatch->setBody(implode("\n", $lines));
        }

        return $this->emitter->file($class, $namespace);
    }

    private function verifiers(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->verifiers($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(Runtime::ENTITY_VERIFIERS);
        $namespace->addUse(Runtime::MUTATION_CONTEXT);
        $namespace->addUse(Runtime::VERIFICATION);

        $context = $this->names->mutationContext($entity);
        $namespace->addUse($context);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(Runtime::ENTITY_VERIFIERS);
        $type->addComment(sprintf('Dispatches to %s\'s field verifiers.', $entity->name));

        $constructor = $type->addMethod('__construct');

        $verified = [];

        foreach ($entity->fields as $field) {
            if (!$field->verify) {
                continue;
            }

            $verified[] = $field->name;

            $handler = $this->names->fieldVerifier($entity, $field);
            $namespace->addUse($handler);

            $constructor->addPromotedParameter($field->name . 'Verifier')
                ->setType($handler)
                ->setPrivate();
        }

        $type->addMethod('verifiedFields')
            ->setReturnType('array')
            ->setBody(sprintf('return %s;', $this->exportList($verified)))
            ->addComment('@return list<string>');

        $dispatch = $type->addMethod('verify')->setReturnType(Runtime::VERIFICATION);
        $dispatch->addParameter('field')->setType('string');
        $dispatch->addParameter('value')->setType('mixed');
        $dispatch->addParameter('context')->setType(Runtime::MUTATION_CONTEXT);

        if ([] === $verified) {
            $dispatch->setBody('return Verification::ok();');

            return $this->emitter->file($class, $namespace);
        }

        $arms = [];

        foreach ($verified as $name) {
            $arms[] = sprintf(
                '    %s => $this->verify%s($value, $context),',
                var_export($name, true),
                ucfirst($name),
            );
        }

        $dispatch->setBody(sprintf(
            "return match (\$field) {\n%s\n    default => Verification::ok(),\n};",
            implode("\n", $arms),
        ));

        foreach ($verified as $name) {
            $field = $entity->field($name);

            if (null === $field) {
                continue;
            }

            $valueType = $this->types->forField($entity, $field);

            if (!in_array($valueType, self::SCALARS, true)) {
                $namespace->addUse($valueType);
            }

            $method = $type->addMethod('verify' . ucfirst($name))
                ->setPrivate()
                ->setReturnType(Runtime::VERIFICATION)
                ->setBody(sprintf(
                    "assert(%s);\n\nreturn \$this->%sVerifier->verify(\$value, %s::of(\$context));",
                    ($field->nullable ? 'null === $value || ' : '') . $this->assertion($valueType),
                    $name,
                    $this->emitter->shortName($context),
                ));

            $method->addParameter('value')->setType('mixed');
            $method->addParameter('context')->setType(Runtime::MUTATION_CONTEXT);
        }

        return $this->emitter->file($class, $namespace);
    }

    private function sideEffects(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->sideEffects($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(Runtime::ENTITY_SIDE_EFFECTS);
        $namespace->addUse(Runtime::MUTABLE_MUTATION_CONTEXT);
        $namespace->addUse(Runtime::SIDE_EFFECT_EVENT);
        $namespace->addUse(Runtime::SIDE_EFFECT_PHASE);

        $context = $this->names->mutationContext($entity);
        $namespace->addUse($context);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(Runtime::ENTITY_SIDE_EFFECTS);
        $type->addComment(sprintf('Runs %s\'s sideEffects, in the order the spec declares them.', $entity->name));

        $constructor = $type->addMethod('__construct');

        foreach ($entity->sideEffects as $sideEffect) {
            $handler = $this->names->sideEffectHandler($entity, $sideEffect->name);
            $namespace->addUse($handler);

            $constructor->addPromotedParameter($sideEffect->name . 'SideEffect')
                ->setType($handler)
                ->setPrivate();
        }

        $dispatch = $type->addMethod('handlers')->setReturnType('iterable')->addComment('@return iterable<callable(): void>');
        $dispatch->addParameter('phase')->setType(Runtime::SIDE_EFFECT_PHASE);
        $dispatch->addParameter('event')->setType(Runtime::SIDE_EFFECT_EVENT);
        $dispatch->addParameter('context')->setType(Runtime::MUTABLE_MUTATION_CONTEXT);

        if ([] === $entity->sideEffects) {
            $dispatch->setBody("\$handlers = [];\nreturn \$handlers;");

            return $this->emitter->file($class, $namespace);
        }

        $lines = ['$handlers = [];'];

        foreach ($entity->sideEffects as $sideEffect) {
            $events = array_map(
                static fn ($event): string => sprintf('SideEffectEvent::%s', ucfirst($event->value)),
                $sideEffect->events,
            );

            $lines[] = sprintf(
                'if (SideEffectPhase::%s === $phase && in_array($event, [%s], true)) {',
                ucfirst($sideEffect->phase->value),
                implode(', ', $events),
            );
            $handlerContext = 'preCommit' === $sideEffect->phase->value ? $this->names->preCommitContext($entity) : $context;
            $namespace->addUse($handlerContext);
            $lines[] = sprintf('    $handlers[] = fn () => $this->%sSideEffect->handle(%s::of($context));', $sideEffect->name, $this->emitter->shortName($handlerContext));
            $lines[] = '}';
            $lines[] = '';
        }

        $lines[] = 'return $handlers;';
        $dispatch->setBody(rtrim(implode("\n", $lines)));
        return $this->emitter->file($class, $namespace);
    }

    private function assertion(string $phpType): string
    {
        return match ($phpType) {
            'string' => 'is_string($value)',
            'int' => 'is_int($value)',
            'float' => 'is_float($value)',
            'bool' => 'is_bool($value)',
            'array' => 'is_array($value)',
            default => sprintf('$value instanceof %s', $this->emitter->shortName($phpType)),
        };
    }

    /**
     * @param list<string> $values
     */
    private function exportList(array $values): string
    {
        if ([] === $values) {
            return '[]';
        }

        return '[' . implode(', ', array_map(
            static fn (string $value): string => var_export($value, true),
            $values,
        )) . ']';
    }
}
