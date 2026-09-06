<?php

declare(strict_types=1);

namespace Eleph\Codegen\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Naming\Emitter;
use Eleph\Codegen\Naming\Names;
use Eleph\Codegen\Naming\TypeMapper;
use Eleph\Runtime\Mutation\EntityTriggers;
use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Runtime\Trigger\TriggerEvent;
use Eleph\Runtime\Trigger\TriggerPhase;
use Eleph\Runtime\Verification\EntityVerifiers;
use Eleph\Runtime\Verification\Verification;
use Eleph\Schema\Ir\EntityDefinition;

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
            $this->triggers($entity),
        ];
    }

    private function verifiers(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->verifiers($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(EntityVerifiers::class);
        $namespace->addUse(MutationContext::class);
        $namespace->addUse(Verification::class);

        $context = $this->names->mutationContext($entity);
        $namespace->addUse($context);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(EntityVerifiers::class);
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

        $dispatch = $type->addMethod('verify')->setReturnType(Verification::class);
        $dispatch->addParameter('field')->setType('string');
        $dispatch->addParameter('value')->setType('mixed');
        $dispatch->addParameter('context')->setType(MutationContext::class);

        if ([] === $verified) {
            $dispatch->setBody('return Verification::ok();');
            $this->emitter->namedConstructor($type, $constructor);

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
                ->setReturnType(Verification::class)
                ->setBody(sprintf(
                    "assert(%s);\n\nreturn \$this->%sVerifier->verify(\$value, %s::of(\$context));",
                    $this->assertion($valueType),
                    $name,
                    $this->emitter->shortName($context),
                ));

            $method->addParameter('value')->setType('mixed');
            $method->addParameter('context')->setType(MutationContext::class);
        }

        $this->emitter->namedConstructor($type, $constructor);

        return $this->emitter->file($class, $namespace);
    }

    private function triggers(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->triggers($entity);
        $namespace = $this->emitter->open($class);
        $namespace->addUse(EntityTriggers::class);
        $namespace->addUse(MutationContext::class);
        $namespace->addUse(TriggerEvent::class);
        $namespace->addUse(TriggerPhase::class);

        $context = $this->names->mutationContext($entity);
        $namespace->addUse($context);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->setReadOnly();
        $type->addImplement(EntityTriggers::class);
        $type->addComment(sprintf('Runs %s\'s triggers, in the order the spec declares them.', $entity->name));

        $constructor = $type->addMethod('__construct');

        foreach ($entity->triggers as $trigger) {
            $handler = $this->names->triggerHandler($entity, $trigger->name);
            $namespace->addUse($handler);

            $constructor->addPromotedParameter($trigger->name . 'Trigger')
                ->setType($handler)
                ->setPrivate();
        }

        $dispatch = $type->addMethod('dispatch')->setReturnType('void');
        $dispatch->addParameter('phase')->setType(TriggerPhase::class);
        $dispatch->addParameter('event')->setType(TriggerEvent::class);
        $dispatch->addParameter('context')->setType(MutationContext::class);

        if ([] === $entity->triggers) {
            $dispatch->setBody('');
            $this->emitter->namedConstructor($type, $constructor);

            return $this->emitter->file($class, $namespace);
        }

        $lines = [sprintf('$typed = %s::of($context);', $this->emitter->shortName($context)), ''];

        foreach ($entity->triggers as $trigger) {
            $events = array_map(
                static fn ($event): string => sprintf('TriggerEvent::%s', ucfirst($event->value)),
                $trigger->events,
            );

            $lines[] = sprintf(
                'if (TriggerPhase::%s === $phase && in_array($event, [%s], true)) {',
                ucfirst($trigger->phase->value),
                implode(', ', $events),
            );
            $lines[] = sprintf('    $this->%sTrigger->handle($typed);', $trigger->name);
            $lines[] = '}';
            $lines[] = '';
        }

        $dispatch->setBody(rtrim(implode("\n", $lines)));
        $this->emitter->namedConstructor($type, $constructor);

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
