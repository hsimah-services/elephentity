<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\Naming\Emitter;
use Eleph\Codegen\Php\Naming\Names;
use Eleph\Codegen\Php\Naming\TypeMapper;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Query\EdgeLoader;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\InverseEdge;
use Eleph\Schema\Ir\Schema;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;

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

        foreach ($this->schema->inversesOf($entity->name) as $inverse) {
            $this->addInverse($namespace, $type, $inverse);
        }

        $this->emitter->namedConstructor($type, $constructor);

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
                ->setBody($this->toOneBody(
                    $targetClass,
                    sprintf(
                        '$this->edges->toOne(%s, $this->id, %s)',
                        var_export($entity->name, true),
                        var_export($edge->name, true),
                    ),
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
            ->setBody($this->toManyBody(
                $targetClass,
                sprintf(
                    '$this->edges->toMany(%s, $this->id, %s)',
                    var_export($entity->name, true),
                    var_export($edge->name, true),
                ),
            ));

        $method->addComment(sprintf('@return EntityQuery<%s>', $this->emitter->shortName($targetClass)));
    }

    /**
     * The accessor on the far side of an edge someone else declared.
     *
     * `inverse:` used to be accepted and generate nothing, which is the worst of the
     * three options available: the spec said `Item.inventoryEntries` existed, validate
     * and check both passed, and the query the data existed to serve — "what is in this
     * location?" — could not be asked.
     *
     * Nothing is stored for it. The loader reads the declaring entity's own edge
     * backwards, so there is one relationship in the schema and one place that decides
     * where it lives.
     */
    private function addInverse(PhpNamespace $namespace, ClassType $type, InverseEdge $inverse): void
    {
        $declaring = $this->schema->entity($inverse->declaredBy);

        if (null === $declaring) {
            return;
        }

        $targetClass = $this->names->entity($declaring);
        $namespace->addUse($targetClass);

        if ($inverse->unique) {
            $method = $type->addMethod($this->names->getter($inverse->name))
                ->setReturnType($targetClass)
                ->setReturnNullable(true)
                ->setBody($this->toOneBody(
                    $targetClass,
                    sprintf(
                        '$this->edges->inverseToOne(%s, %s, $this->id)',
                        var_export($inverse->declaredBy, true),
                        var_export($inverse->edge, true),
                    ),
                ));

            $method->addComment(sprintf(
                'The %s whose "%s" points here.',
                $inverse->declaredBy,
                $inverse->edge,
            ));
            $method->addComment('');
            $method->addComment(sprintf('@return %s|null', $this->emitter->shortName($targetClass)));

            return;
        }

        $namespace->addUse(EntityQuery::class);

        $method = $type->addMethod($inverse->name)
            ->setReturnType(EntityQuery::class)
            ->setBody($this->toManyBody(
                $targetClass,
                sprintf(
                    '$this->edges->inverseToMany(%s, %s, $this->id)',
                    var_export($inverse->declaredBy, true),
                    var_export($inverse->edge, true),
                ),
            ));

        $method->addComment(sprintf(
            'Every %s whose "%s" points here.',
            $inverse->declaredBy,
            $inverse->edge,
        ));
        $method->addComment('');
        $method->addComment(sprintf('@return EntityQuery<%s>', $this->emitter->shortName($targetClass)));
    }

    /**
     * The loader is addressed by name, so it hands back `?object`; the accessor
     * promises the entity the spec says is on the other end.
     *
     * assert() is the right tool, and the same one the mutation contexts use: PHPStan
     * narrows on it, and in production it compiles away — so the exact typing the
     * whole framework rests on costs nothing at runtime.
     */
    private function toOneBody(string $targetClass, string $call): string
    {
        $short = $this->emitter->shortName($targetClass);

        return sprintf(
            "\$related = %s;\nassert(null === \$related || \$related instanceof %s);\n\nreturn \$related;",
            $call,
            $short,
        );
    }

    /**
     * The same gap, for a set.
     *
     * A generic cannot be asserted at runtime, so the narrowing is a docblock. It is
     * still checked: the loader's own map decides what hydrates, and both it and this
     * come from the same spec.
     */
    private function toManyBody(string $targetClass, string $call): string
    {
        return sprintf(
            "/** @var EntityQuery<%s> \$related */\n\$related = %s;\n\nreturn \$related;",
            $this->emitter->shortName($targetClass),
            $call,
        );
    }

    private function isScalar(string $type): bool
    {
        return in_array($type, ['string', 'int', 'float', 'bool', 'array'], true);
    }
}
