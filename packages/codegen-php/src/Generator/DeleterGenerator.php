<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\Naming\Emitter;
use Eleph\Codegen\Php\Naming\Names;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Storage\DeletionPolicy;
use Eleph\Runtime\Storage\DeletionRule;
use Eleph\Runtime\UnitOfWork\UnitOfWork;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\OnDelete;
use Eleph\Schema\Ir\Schema;

/**
 * Emits the class that removes an entity, and the rules that removal implies.
 *
 * Its own class rather than a method on the mutator: a mutator you hold to change a
 * title should not also be able to destroy the row, and "who is allowed to delete
 * this" is a question worth being able to answer by looking at what was injected.
 *
 * The rules are generated rather than resolved at runtime because the edge graph is a
 * build-time fact. Working out what depends on a Post means reading every other
 * entity's edges, and that is exactly the kind of thing to do once, here.
 */
final readonly class DeleterGenerator
{
    public function __construct(
        private Schema $schema,
        private Names $names,
        private Emitter $emitter,
    ) {
    }

    public function generate(EntityDefinition $entity): GeneratedFile
    {
        $class = $this->names->deleter($entity);
        $namespace = $this->emitter->open($class);

        $namespace->addUse(EntityId::class);
        $namespace->addUse(Deletion::class);
        $namespace->addUse(UnitOfWork::class);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->addComment(sprintf('Removes a %s, and whatever its edges say goes with it.', $entity->name));

        $type->addMethod('__construct')
            ->addPromotedParameter('work')
            ->setType(UnitOfWork::class)
            ->setPrivate()
            ->setReadOnly();

        $delete = $type->addMethod('delete')
            ->setReturnType('void')
            ->setBody(sprintf(
                '$this->work->delete(new Deletion(%s, $id));',
                var_export($entity->name, true),
            ));

        $delete->addParameter('id')->setType(EntityId::class);
        $delete->addComment('Registers the removal. Nothing happens until the unit of work commits.');

        $rules = $this->rules($entity);

        if ([] !== $rules) {
            $namespace->addUse(DeletionPolicy::class);
            $namespace->addUse(DeletionRule::class);
        }

        $type->addMethod('rules')
            ->setStatic()
            ->setReturnType('array')
            ->setBody($this->rulesBody($rules))
            ->addComment('@return list<DeletionRule>');

        return $this->emitter->file($class, $namespace);
    }

    /**
     * Everything that depends on this entity, found by reading every other entity's
     * edges rather than only its own — a Post learns about Comments from Comment.
     *
     * @return list<array{dependent: string, edge: string, declaredBy: string, policy: OnDelete, join: bool}>
     */
    private function rules(EntityDefinition $entity): array
    {
        $rules = [];

        foreach ($this->schema->entities as $other) {
            foreach ($other->edges as $edge) {
                $relation = $edge->relation();

                // Whoever holds the key is the dependent. For a locally-keyed edge
                // that is the declaring entity; otherwise it is the target.
                $dependent = $relation->keyIsLocal() ? $other->name : $edge->to;
                $referenced = $relation->keyIsLocal() ? $edge->to : $other->name;

                if ($relation->needsJoinTable()) {
                    // Either side deleting leaves join rows behind, so both get a rule.
                    $referenced = $other->name === $entity->name ? $other->name : $edge->to;
                    $dependent = $other->name === $entity->name ? $edge->to : $other->name;
                }

                if ($referenced !== $entity->name) {
                    continue;
                }

                $rules[] = [
                    'dependent' => $dependent,
                    'edge' => $edge->name,
                    'declaredBy' => $other->name,
                    'policy' => $edge->onDelete,
                    'join' => $relation->needsJoinTable(),
                ];
            }
        }

        return $rules;
    }

    /**
     * @param list<array{dependent: string, edge: string, declaredBy: string, policy: OnDelete, join: bool}> $rules
     */
    private function rulesBody(array $rules): string
    {
        if ([] === $rules) {
            return 'return [];';
        }

        $lines = [];

        foreach ($rules as $rule) {
            $lines[] = sprintf(
                '    new DeletionRule(%s, %s, %s, DeletionPolicy::%s, %s),',
                var_export($rule['dependent'], true),
                var_export($rule['edge'], true),
                var_export($rule['declaredBy'], true),
                ucfirst($rule['policy']->value),
                $rule['join'] ? 'true' : 'false',
            );
        }

        return sprintf("return [\n%s\n];", implode("\n", $lines));
    }
}
