<?php

declare(strict_types=1);

namespace Eleph\Schema;

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\Ir\TypeReference;

/**
 * Checks that a structurally valid schema also means something.
 *
 * JSON Schema establishes shape. This establishes closure: every edge target resolves,
 * every declared type exists, every inverse is derivable, every action writes only
 * members it could write. A schema that passes here is safe for the generator to
 * consume without defensive checks of its own.
 */
final readonly class SemanticValidator
{
    /**
     * WordPress post type slugs are capped at 20 characters and must be lowercase.
     * Exceeding it means WP silently truncates at registration.
     */
    private const WORDPRESS_HANDLE_LIMIT = 20;

    /**
     * MariaDB with DYNAMIC row format allows 3072 bytes per index, and utf8mb4 is
     * 4 bytes per character.
     */
    private const INDEX_CHARACTER_LIMIT = 768;

    private const DEFAULT_STRING_LENGTH = 255;

    /**
     * Entity names the generated tree already uses for its shared folders.
     *
     * `Enum/` holds every enum and `Type/` every processor, so an entity of either
     * name would land its own classes in a folder that means something else. Both are
     * too vague to be a real entity anyway, so refusing them costs nothing.
     */
    private const RESERVED_ENTITY_NAMES = ['Enum', 'Type'];

    /**
     * @return list<SpecError>
     */
    public function validate(Schema $schema): array
    {
        $errors = [];

        $this->checkEntityNames($schema, $errors);
        $this->checkTables($schema, $errors);
        $this->checkTypes($schema, $errors);

        foreach ($schema->entities as $entity) {
            $this->checkStorage($entity, $errors);

            foreach ($entity->fields as $field) {
                $this->checkField($schema, $entity, $field, $errors);
            }

            foreach ($entity->edges as $edge) {
                $this->checkEdge($schema, $entity, $edge, $errors);
            }

            $this->checkQueries($schema, $entity, $errors);
            $this->checkActions($entity, $errors);
        }

        return $errors;
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkEntityNames(Schema $schema, array &$errors): void
    {
        foreach ($schema->entities as $entity) {
            if (!in_array($entity->name, self::RESERVED_ENTITY_NAMES, true)) {
                continue;
            }

            $errors[] = new SpecError(
                'entity.reservedName',
                sprintf(
                    '"%s" is reserved: the generated tree uses a folder of that name for every %s in the schema.',
                    $entity->name,
                    'Enum' === $entity->name ? 'enum' : 'type processor',
                ),
                $entity->sourceFile,
                '/entity',
            );
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkTables(Schema $schema, array &$errors): void
    {
        /** @var array<string, string> $seen */
        $seen = [];

        foreach ($schema->entities as $entity) {
            $table = $entity->storage->table;
            $owner = $seen[$table] ?? null;

            if (null !== $owner) {
                $errors[] = new SpecError(
                    'storage.tableConflict',
                    sprintf('Entities %s and %s both map to table "%s".', $owner, $entity->name, $table),
                    $entity->sourceFile,
                    '/storage/table',
                );

                continue;
            }

            $seen[$table] = $entity->name;
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkTypes(Schema $schema, array &$errors): void
    {
        foreach ($schema->types as $type) {
            if (!$type->isEnum()) {
                continue;
            }

            if (!in_array($type->primitive, Primitive::enumBackings(), true)) {
                $errors[] = new SpecError(
                    'type.enumBacking',
                    sprintf(
                        'Enum type "%s" is backed by %s; a declared enum must be backed by string or int.',
                        $type->name,
                        $type->primitive->value,
                    ),
                    $type->sourceFile,
                    '/primitive',
                );
            }
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkStorage(EntityDefinition $entity, array &$errors): void
    {
        $handle = $entity->storage->handle;

        if ('wordpress' !== $entity->storage->driver || null === $handle) {
            return;
        }

        if (strlen($handle) > self::WORDPRESS_HANDLE_LIMIT) {
            $errors[] = new SpecError(
                'storage.handleTooLong',
                sprintf(
                    'Handle "%s" is %d characters; WordPress post type slugs are capped at %d and are silently truncated at registration.',
                    $handle,
                    strlen($handle),
                    self::WORDPRESS_HANDLE_LIMIT,
                ),
                $entity->sourceFile,
                '/storage/handle',
            );
        }

        if (1 !== preg_match('/^[a-z][a-z0-9_-]*$/', $handle)) {
            $errors[] = new SpecError(
                'storage.handleInvalid',
                sprintf('Handle "%s" must be lowercase and may contain only letters, digits, hyphens and underscores.', $handle),
                $entity->sourceFile,
                '/storage/handle',
            );
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkField(
        Schema $schema,
        EntityDefinition $entity,
        FieldDefinition $field,
        array &$errors,
    ): void {
        $pointer = sprintf('/fields/%s', $field->name);

        if ('id' === $field->name) {
            $errors[] = new SpecError(
                'field.reserved',
                'Every entity has an implicit "id"; it cannot be declared or overridden.',
                $entity->sourceFile,
                $pointer,
            );
        }

        $this->checkTypeReference($schema, $field->type, $entity, $pointer, $errors);

        $primitive = $field->type->primitive;

        if (Primitive::Enum === $primitive && null === $field->enum) {
            $errors[] = new SpecError(
                'field.enumWithoutValues',
                sprintf('Field "%s" is an enum but declares no values.', $field->name),
                $entity->sourceFile,
                $pointer,
            );
        }

        if (Primitive::Enum !== $primitive && null !== $field->enum) {
            $errors[] = new SpecError(
                'field.valuesWithoutEnum',
                sprintf('Field "%s" declares values but is not of type enum.', $field->name),
                $entity->sourceFile,
                $pointer,
            );
        }

        $this->checkEnumSource($schema, $entity, $field, $pointer, $errors);

        if (null !== $field->maxLength && Primitive::String !== $primitive) {
            $errors[] = new SpecError(
                'field.maxLengthNotSizable',
                sprintf(
                    'Field "%s" declares maxLength but is of type %s; only string is sized.',
                    $field->name,
                    $field->type->name(),
                ),
                $entity->sourceFile,
                $pointer,
            );
        }

        $this->checkIndexWidth($entity, $field, $pointer, $errors);
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkEnumSource(
        Schema $schema,
        EntityDefinition $entity,
        FieldDefinition $field,
        string $pointer,
        array &$errors,
    ): void {
        $enum = $field->enum;

        if (null === $enum) {
            return;
        }

        if ($enum->isInline()) {
            // An inline enum generates a class named from entity + field. A declared
            // type of the same name would be silently overwritten, so reject it.
            $derived = $entity->name . ucfirst($field->name);

            if (null !== $schema->type($derived)) {
                $errors[] = new SpecError(
                    'field.enumNameCollision',
                    sprintf(
                        'Inline enum "%s.%s" generates "%s", which is already declared as a type. Reference it with "values: %s" instead.',
                        $entity->name,
                        $field->name,
                        $derived,
                        $derived,
                    ),
                    $entity->sourceFile,
                    $pointer,
                );
            }

            return;
        }

        $name = (string) $enum->declaredType;
        $type = $schema->type($name);

        if (null === $type) {
            $errors[] = new SpecError(
                'field.unknownEnum',
                sprintf('Field "%s" references unknown enum "%s". Expected types/%s.yml.', $field->name, $name, $name),
                $entity->sourceFile,
                $pointer,
            );

            return;
        }

        if (!$type->isEnum()) {
            $errors[] = new SpecError(
                'field.notAnEnum',
                sprintf('Field "%s" references type "%s", which declares no values and so is not an enum.', $field->name, $name),
                $entity->sourceFile,
                $pointer,
            );
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkIndexWidth(
        EntityDefinition $entity,
        FieldDefinition $field,
        string $pointer,
        array &$errors,
    ): void {
        if (Primitive::String !== $field->type->primitive) {
            return;
        }

        if (!$field->indexed && !$field->unique) {
            return;
        }

        $width = $field->maxLength ?? self::DEFAULT_STRING_LENGTH;

        if ($width <= self::INDEX_CHARACTER_LIMIT) {
            return;
        }

        // Never silently prefix-index: with unique: true a prefix index enforces
        // uniqueness of the prefix, so two genuinely different values collide and
        // nothing tells you.
        $errors[] = new SpecError(
            'field.indexTooWide',
            sprintf(
                'Field "%s" is indexed at maxLength %d, beyond the %d-character index limit. Narrow it rather than accepting a prefix index, which would enforce uniqueness of the prefix only.',
                $field->name,
                $width,
                self::INDEX_CHARACTER_LIMIT,
            ),
            $entity->sourceFile,
            $pointer,
        );
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkEdge(
        Schema $schema,
        EntityDefinition $entity,
        EdgeDefinition $edge,
        array &$errors,
    ): void {
        $pointer = sprintf('/edges/%s', $edge->name);
        $target = $schema->entity($edge->to);

        if (null === $target) {
            $errors[] = new SpecError(
                'edge.unknownTarget',
                sprintf('Edge "%s" points at unknown entity "%s".', $edge->name, $edge->to),
                $entity->sourceFile,
                $pointer,
            );
        }

        $inverse = $edge->inverse;

        if (null === $inverse) {
            return;
        }

        // Derivability does not depend on the target, so it is checked even when the
        // target is missing. Bailing early here would hide a second problem behind the
        // first and cost the author another round trip.
        if ($inverse->derived && !$inverse->unique) {
            $errors[] = new SpecError(
                'edge.inverseNotDerivable',
                sprintf(
                    'Edge "%s" declares a non-unique reverse, so the derived name would have to be pluralised. Name it explicitly: inverse: { name: <name>, unique: false }.',
                    $edge->name,
                ),
                $entity->sourceFile,
                $pointer,
            );

            return;
        }

        if (null === $target) {
            return;
        }

        $name = $inverse->nameFor($entity->name);

        if (null !== $target->field($name) || null !== $target->edge($name)) {
            $errors[] = new SpecError(
                'edge.inverseCollision',
                sprintf(
                    'Edge "%s" would generate "%s" on %s, which already declares a member of that name.',
                    $edge->name,
                    $name,
                    $target->name,
                ),
                $entity->sourceFile,
                $pointer,
            );
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkQueries(Schema $schema, EntityDefinition $entity, array &$errors): void
    {
        foreach ($entity->queries as $query) {
            $pointer = sprintf('/queries/%s', $query->name);

            if (!$schema->hasEntity($query->returns->type)) {
                $errors[] = new SpecError(
                    'query.unknownReturn',
                    sprintf('Query "%s" returns unknown entity "%s".', $query->name, $query->returns->type),
                    $entity->sourceFile,
                    $pointer,
                );
            }

            foreach ($query->arguments as $argument) {
                $this->checkTypeReference($schema, $argument->type, $entity, $pointer, $errors);
            }
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkActions(EntityDefinition $entity, array &$errors): void
    {
        foreach ($entity->actions as $action) {
            $pointer = sprintf('/actions/%s', $action->name);

            foreach ($action->writes->fields as $field) {
                if (null === $entity->field($field)) {
                    $errors[] = new SpecError(
                        'action.unknownWrite',
                        sprintf('Action "%s" declares it writes field "%s", which %s does not declare.', $action->name, $field, $entity->name),
                        $entity->sourceFile,
                        $pointer,
                    );
                }
            }

            foreach ($action->writes->edges as $edge) {
                if (null === $entity->edge($edge)) {
                    $errors[] = new SpecError(
                        'action.unknownWrite',
                        sprintf('Action "%s" declares it writes edge "%s", which %s does not declare.', $action->name, $edge, $entity->name),
                        $entity->sourceFile,
                        $pointer,
                    );
                }
            }
        }
    }

    /**
     * @param list<SpecError> $errors
     */
    private function checkTypeReference(
        Schema $schema,
        TypeReference $reference,
        EntityDefinition $entity,
        string $pointer,
        array &$errors,
    ): void {
        if ($reference->isPrimitive()) {
            return;
        }

        $name = (string) $reference->declaredType;

        if (null === $schema->type($name)) {
            $errors[] = new SpecError(
                'type.unknown',
                sprintf('Unknown type "%s". Expected a primitive or types/%s.yml.', $name, $name),
                $entity->sourceFile,
                $pointer,
            );
        }
    }
}
