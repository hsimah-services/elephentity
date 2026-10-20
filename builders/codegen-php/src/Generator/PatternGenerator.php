<?php

declare(strict_types=1);

namespace Eleph\Gen\Php\Generator;

use Eleph\Gen\Php\GeneratedFile;
use Eleph\Gen\Php\Ir\Cardinality;
use Eleph\Gen\Php\Ir\EdgeDefinition;
use Eleph\Gen\Php\Ir\EntityDefinition;
use Eleph\Gen\Php\Ir\PatternDeclaration;
use Eleph\Gen\Php\Ir\Schema;
use Eleph\Gen\Php\Ir\StorageDefinition;
use Eleph\Gen\Php\Naming\Emitter;
use Eleph\Gen\Php\Naming\Names;
use Eleph\Gen\Php\Naming\TypeMapper;
use Eleph\Gen\Php\Runtime;
use Nette\PhpGenerator\InterfaceType;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\TraitType;

/**
 * Emits, once per pattern that opted in, a shared interface for its fields and edges
 * and a trait for writing them.
 *
 * Everything here is generated in full — unlike ContractGenerator's interfaces, there
 * is nothing for an application to implement. A pattern's fields are the same shape on
 * every entity using it, so the entity's own already-generated getters already satisfy
 * the interface, and the trait's setters already know how to reach the buffer; both
 * exist purely so code that only cares about "the Auditable part" of something can say
 * so, instead of naming every entity that happens to have one.
 *
 * The trait, not a base class: PHP allows one parent but many traits, and two patterns
 * on the same entity both wanting to contribute setters — Auditable and SoftDeletable
 * on the same Post — is not hypothetical. `{Entity}Mutator::use`s as many pattern
 * traits as the entity applies.
 */
final readonly class PatternGenerator
{
    private const SCALARS = ['string', 'int', 'float', 'bool', 'array'];

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
    public function generate(): array
    {
        $files = [];

        foreach ($this->schema->patterns as $pattern) {
            $files[] = $this->contract($pattern);
            $files[] = $this->mutatorTrait($pattern);
        }

        return $files;
    }

    private function contract(PatternDeclaration $pattern): GeneratedFile
    {
        $class = $this->names->patternContract($pattern->name);
        $namespace = $this->emitter->open($class);

        $interface = $namespace->addInterface($this->emitter->shortName($class));
        $interface->addComment(sprintf(
            'The %s fields and edges, for code that only needs those and not the whole entity.',
            $pattern->name,
        ));
        $interface->addComment('');
        $interface->addComment(sprintf(
            'Every entity using %s already has these getters — this names the shape, it does',
            $pattern->name,
        ));
        $interface->addComment('not add anything an application has to implement.');

        $subject = $this->subject($pattern);

        foreach ($pattern->fields as $field) {
            $phpType = $this->types->forField($subject, $field);

            if (!in_array($phpType, self::SCALARS, true)) {
                $namespace->addUse($phpType);
            }

            $getter = $interface->addMethod($this->names->getter($field->name))
                ->setPublic()
                ->setReturnType($phpType)
                ->setReturnNullable($field->nullable);

            if (null !== $field->description) {
                $getter->addComment($field->description);
            }
        }

        foreach ($pattern->edges as $edge) {
            $this->addEdgeRead($namespace, $interface, $edge);
        }

        return $this->emitter->file($class, $namespace);
    }

    private function mutatorTrait(PatternDeclaration $pattern): GeneratedFile
    {
        $class = $this->names->patternMutatorTrait($pattern->name);
        $namespace = $this->emitter->open($class);

        $trait = $namespace->addTrait($this->emitter->shortName($class));
        $trait->addComment(sprintf(
            '%s\'s own setters, mixed into every entity mutator that applies it.',
            $pattern->name,
        ));
        $trait->addComment('');
        $trait->addComment('Assumes the using class has a private MutationBuffer $buffer — true of every');
        $trait->addComment('generated {Entity}Mutator, and the only thing this trait requires of it.');

        $subject = $this->subject($pattern);

        foreach ($pattern->fields as $field) {
            if ($field->immutable || null !== $field->managed) {
                continue;
            }

            $phpType = $this->types->forField($subject, $field);

            if (!in_array($phpType, self::SCALARS, true)) {
                $namespace->addUse($phpType);
            }

            $setter = $trait->addMethod($this->names->setter($field->name))
                ->setReturnType('self')
                ->setBody(sprintf(
                    "\$this->buffer->set(%s, \$%s);\n\nreturn \$this;",
                    var_export($field->name, true),
                    $field->name,
                ));

            $setter->addParameter($field->name)->setType($phpType)->setNullable($field->nullable);
        }

        foreach ($pattern->edges as $edge) {
            $this->addEdgeWrite($namespace, $trait, $edge);
        }

        return $this->emitter->file($class, $namespace);
    }

    private function addEdgeRead(PhpNamespace $namespace, InterfaceType $interface, EdgeDefinition $edge): void
    {
        $target = $this->schema->entity($edge->to);

        if (null === $target) {
            return;
        }

        $targetClass = $this->names->entity($target);
        $namespace->addUse($targetClass);

        if (Cardinality::One === $edge->cardinality) {
            $interface->addMethod($this->names->getter($edge->name))
                ->setPublic()
                ->setReturnType($targetClass)
                ->setReturnNullable(true);

            return;
        }

        $namespace->addUse(Runtime::ENTITY_QUERY);
        $method = $interface->addMethod($edge->name)->setPublic()->setReturnType(Runtime::ENTITY_QUERY);
        $method->addComment(sprintf('@return EntityQuery<%s>', $this->emitter->shortName($targetClass)));
    }

    private function addEdgeWrite(PhpNamespace $namespace, TraitType $trait, EdgeDefinition $edge): void
    {
        if (Cardinality::One === $edge->cardinality) {
            $namespace->addUse(Runtime::IDENTIFIER);

            $setter = $trait->addMethod($this->names->setter($edge->name))
                ->setReturnType('self')
                ->setBody(sprintf(
                    "\$this->buffer->edge(%s)->set(null === \$%s ? [] : [\$%s]);\n\nreturn \$this;",
                    var_export($edge->name, true),
                    $edge->name,
                    $edge->name,
                ));

            $setter->addParameter($edge->name)->setType(Runtime::IDENTIFIER)->setNullable(true);

            return;
        }

        $namespace->addUse(Runtime::EDGE_MUTATION);
        $trait->addMethod($edge->name)
            ->setReturnType(Runtime::EDGE_MUTATION)
            ->setBody(sprintf('return $this->buffer->edge(%s);', var_export($edge->name, true)));
    }

    /**
     * A throwaway entity, named after the pattern, purely so TypeMapper has something
     * to prefix an inline enum's class name with. A pattern's own fields are the same
     * shape everywhere, so any name would do; the pattern's own is the least surprising
     * choice, and one that cannot collide with a real entity — pattern and entity names
     * share a namespace the spec compiler already keeps distinct.
     */
    private function subject(PatternDeclaration $pattern): EntityDefinition
    {
        return new EntityDefinition(
            name: $pattern->name,
            storage: new StorageDefinition($this->schema->project->driver, $pattern->name),
            sourceFile: $pattern->name,
        );
    }
}
