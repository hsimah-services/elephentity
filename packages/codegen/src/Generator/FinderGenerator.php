<?php

declare(strict_types=1);

namespace PheFr\Codegen\Generator;

use PheFr\Codegen\GeneratedFile;
use PheFr\Codegen\Naming\Emitter;
use PheFr\Codegen\Naming\Names;
use PheFr\Codegen\Naming\TypeMapper;
use PheFr\Runtime\Query\EntityQuery;
use PheFr\Schema\Ir\Cardinality;
use PheFr\Schema\Ir\EntityDefinition;
use PheFr\Schema\Ir\Schema;

/**
 * Emits the collection gateway: one method per declared query.
 *
 * A separate injectable class rather than statics on the entity. Statics are awkward
 * to inject into and to fake in tests, and keeping finders out of the entity leaves it
 * exactly one thing — a single-row read model — rather than also a collection gateway.
 *
 * Finders return the same lazy query edges return, so page() and count() behave
 * identically however you arrived at a set.
 */
final readonly class FinderGenerator
{
    public function __construct(
        private Schema $schema,
        private Names $names,
        private TypeMapper $types,
        private Emitter $emitter,
    ) {
    }

    public function generate(EntityDefinition $entity): ?GeneratedFile
    {
        if ([] === $entity->queries) {
            return null;
        }

        $class = $this->names->finder($entity);
        $namespace = $this->emitter->open($class);

        $type = $namespace->addClass($this->emitter->shortName($class));
        $type->setFinal();
        $type->addComment(sprintf('Collection-level queries over %s.', $entity->name));

        $constructor = $type->addMethod('__construct');

        foreach ($entity->queries as $query) {
            $handler = $this->names->queryHandler($entity, $query->name);
            $namespace->addUse($handler);

            $constructor->addPromotedParameter($query->name . 'Query')
                ->setType($handler)
                ->setPrivate()
                ->setReadOnly();
        }

        foreach ($entity->queries as $query) {
            $returns = $this->schema->entity($query->returns->type);

            if (null === $returns) {
                continue;
            }

            $returnClass = $this->names->entity($returns);
            $namespace->addUse($returnClass);

            $arguments = [];

            foreach ($query->arguments as $argument) {
                $arguments[] = '$' . $argument->name;
            }

            $method = $type->addMethod($query->name);

            foreach ($query->arguments as $argument) {
                $argumentType = $this->types->forArgument($argument);

                if (!in_array($argumentType, ['string', 'int', 'float', 'bool', 'array'], true)) {
                    $namespace->addUse($argumentType);
                }

                $parameter = $method->addParameter($argument->name)
                    ->setType($argumentType)
                    ->setNullable($argument->nullable);

                if ($argument->nullable) {
                    $parameter->setDefaultValue(null);
                }
            }

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

            $method->setBody(sprintf(
                'return $this->%sQuery->find(%s);',
                $query->name,
                implode(', ', $arguments),
            ));
        }

        $this->emitter->namedConstructor($type, $constructor);

        return $this->emitter->file($class, $namespace);
    }
}
