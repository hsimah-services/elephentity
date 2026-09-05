<?php

declare(strict_types=1);

namespace PheFr\Codegen\Generator;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;
use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\Naming\Emitter;
use PheFr\Codegen\Naming\Names;
use PheFr\Codegen\Naming\TypeMapper;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Query\EdgeLoader;
use PheFr\Runtime\Query\EntityQuery;
use PheFr\Schema\Ir\Cardinality;
use PheFr\Schema\Ir\EdgeDefinition;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\Schema;

/**
 * Emits the read model: an immutable snapshot of one row.
 *
 * Fields arrive already in domain form, so every accessor is a plain typed read with
 * no casting and nothing that can fail. Edges are not held at all — the entity keeps an
 * EdgeLoader instead, so nothing related is fetched until asked for, and when many
 * entities ask at once the loader batches.
 */
final readonly class EntityGenerator
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
        $class = $this->names->entity($entity);
        $namespace = $this->emitter->open($class);

        $namespace->addUse(EntityId::class);
        $namespace->addUse(EdgeLoader::class);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->addComment($entity->description ?? sprintf('%s, as stored.', $entity->name));

        $constructor = $type->addMethod('__construct');

        $constructor->addPromotedParameter('id')
            ->setType(EntityId::class)
            ->setPrivate()
            ->setReadOnly();

        $constructor->addPromotedParameter('edges')
            ->setType(EdgeLoader::class)
            ->setPrivate()
            ->setReadOnly();

        $type->addMethod('getId')
            ->setReturnType(EntityId::class)
            ->setBody('return $this->id;');

        foreach ($entity->fields as $field) {
            $phpType = $this->types->forField($entity, $field);

            if (!$this->isScalar($phpType)) {
                $namespace->addUse($phpType);
            }

            $constructor->addPromotedParameter($field->name)
                ->setType($phpType)
                ->setNullable($field->nullable)
                ->setPrivate()
                ->setReadOnly();

            $getter = $type->addMethod($this->names->getter($field->name))
                ->setReturnType($phpType)
                ->setReturnNullable($field->nullable)
                ->setBody(sprintf('return $this->%s;', $field->name));

            if (null !== $field->description) {
                $getter->addComment($field->description);
            }
        }

        foreach ($entity->edges as $edge) {
            $this->addEdge($namespace, $type, $entity, $edge);
        }

        return $this->emitter->file($class, $namespace);
    }

    private function addEdge(
        PhpNamespace $namespace,
        ClassType $type,
        EntityDefinition $entity,
        EdgeDefinition $edge,
    ): void {
        $target = $this->schema->entity($edge->to);

        if (null === $target) {
            return;
        }

        $targetClass = $this->names->entity($target);
        $namespace->addUse($targetClass);

        if (Cardinality::One === $edge->cardinality) {
            $method = $type->addMethod($this->names->getter($edge->name))
                ->setReturnType($targetClass)
                ->setReturnNullable(true)
                ->setBody(sprintf(
                    'return $this->edges->toOne(%s, $this->id, %s);',
                    var_export($entity->name, true),
                    var_export($edge->name, true),
                ));

            $method->addComment(sprintf('@return %s|null', $this->emitter->shortName($targetClass)));

            return;
        }

        $namespace->addUse(EntityQuery::class);

        // A lazy query rather than an array: an unbounded load becomes a deliberate
        // all(), GraphQL connections map onto page() directly, and the loader can
        // batch across a result set instead of issuing one query per parent.
        $method = $type->addMethod($edge->name)
            ->setReturnType(EntityQuery::class)
            ->setBody(sprintf(
                'return $this->edges->toMany(%s, $this->id, %s);',
                var_export($entity->name, true),
                var_export($edge->name, true),
            ));

        $method->addComment(sprintf('@return EntityQuery<%s>', $this->emitter->shortName($targetClass)));
    }

    private function isScalar(string $type): bool
    {
        return in_array($type, ['string', 'int', 'float', 'bool', 'array'], true);
    }
}
