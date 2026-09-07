<?php

declare(strict_types=1);

namespace Eleph\Codegen\Php\Generator;

use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Php\Naming\Emitter;
use Eleph\Codegen\Php\Naming\Names;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\Schema;

/**
 * Emits backed enums.
 *
 * The one type the generator owns outright: an enum is pure data, so there is nothing
 * for a human to add and no reason to make them write it.
 *
 * Inline and declared enums produce the same fully-qualified name, which is what makes
 * promoting an inline enum into types/ a no-op in the generated code.
 */
final readonly class EnumGenerator
{
    public function __construct(
        private Names $names,
        private Emitter $emitter,
    ) {
    }

    /**
     * @return list<GeneratedFile>
     */
    public function generate(Schema $schema): array
    {
        $files = [];

        foreach ($schema->types as $type) {
            if (!$type->isEnum()) {
                continue;
            }

            $files[] = $this->emit(
                $this->names->enum($type->name),
                $type->values ?? [],
                $type->primitive,
                $type->description,
            );
        }

        foreach ($schema->entities as $entity) {
            foreach ($this->inlineEnums($entity) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<GeneratedFile>
     */
    private function inlineEnums(EntityDefinition $entity): array
    {
        $files = [];

        foreach ($entity->fields as $field) {
            $enum = $field->enum;

            if (null === $enum || !$enum->isInline()) {
                continue;
            }

            $files[] = $this->emit(
                $this->names->inlineEnum($entity, $field),
                $enum->inlineValues ?? [],
                Primitive::String,
                sprintf('Values of %s::%s.', $entity->name, $field->name),
            );
        }

        return $files;
    }

    /**
     * @param list<string> $values
     */
    private function emit(
        string $fullyQualified,
        array $values,
        Primitive $backing,
        ?string $description,
    ): GeneratedFile {
        $namespace = $this->emitter->open($fullyQualified);
        $enum = $namespace->addEnum($this->emitter->shortName($fullyQualified));

        $enum->setType(Primitive::Int === $backing ? 'int' : 'string');

        if (null !== $description) {
            $enum->addComment($description);
        }

        foreach ($values as $index => $value) {
            $enum->addCase(
                $this->caseName($value),
                Primitive::Int === $backing ? $index : $value,
            );
        }

        return $this->emitter->file($fullyQualified, $namespace);
    }

    private function caseName(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $value)));
    }
}
