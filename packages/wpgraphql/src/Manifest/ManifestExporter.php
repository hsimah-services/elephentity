<?php

declare(strict_types=1);

namespace PheFr\WPGraphQL\Manifest;

/**
 * Writes a manifest out as PHP source that rebuilds it.
 *
 * Constructor calls rather than nested arrays, for two reasons: the file type-checks
 * like any other code, so a manifest that no longer matches the value objects fails at
 * build time rather than at the first request; and a diff reads as a description of
 * the API surface, which is exactly what a reviewer wants to see when a spec changes.
 */
final readonly class ManifestExporter
{
    public function export(Manifest $manifest): string
    {
        return sprintf(
            <<<'PHP'
                namespace %s;

                /**
                 * The compiled GraphQL surface.
                 *
                 * Loaded at boot and registered as-is: every decision was made when this was
                 * compiled, so nothing here is worked out per request.
                 */
                return new Manifest(
                    objects: [
                %s
                    ],
                    enums: [
                %s
                    ],
                    mutations: [
                %s
                    ],
                );

                PHP,
            __NAMESPACE__,
            $this->objects($manifest),
            $this->enums($manifest),
            $this->mutations($manifest),
        );
    }

    private function objects(Manifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->objects as $object) {
            $fields = [];

            foreach ($object->fields as $field) {
                $fields[] = sprintf(
                    '                %s => new FieldEntry(%s, %s, %s, %s),',
                    $this->str($field->name),
                    $this->str($field->name),
                    $this->type($field->type),
                    $this->str($field->accessor),
                    $this->nullableStr($field->description),
                );
            }

            $connections = [];

            foreach ($object->connections as $connection) {
                $connections[] = sprintf(
                    '                %s => new ConnectionEntry(%s, %s, %s, %s, %s, %s),',
                    $this->str($connection->name),
                    $this->str($connection->name),
                    $this->str($connection->fromType),
                    $this->str($connection->toType),
                    $this->str($connection->accessor),
                    $this->str($connection->edge),
                    $this->nullableStr($connection->description),
                );
            }

            $lines[] = sprintf(
                "        %s => new ObjectTypeEntry(\n            %s,\n            %s,\n            %s,\n            %s,\n            %s,\n        ),",
                $this->str($object->name),
                $this->str($object->name),
                $this->str($object->entity),
                $this->block($fields),
                $this->block($connections),
                $this->nullableStr($object->description),
            );
        }

        return implode("\n", $lines);
    }

    private function enums(Manifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->enums as $enum) {
            $values = [];

            foreach ($enum->values as $name => $value) {
                $values[] = sprintf('%s => %s', $this->str($name), $this->str($value));
            }

            $lines[] = sprintf(
                '        %s => new EnumTypeEntry(%s, [%s]),',
                $this->str($enum->name),
                $this->str($enum->name),
                implode(', ', $values),
            );
        }

        return implode("\n", $lines);
    }

    private function mutations(Manifest $manifest): string
    {
        $lines = [];

        foreach ($manifest->mutations as $mutation) {
            $inputs = [];

            foreach ($mutation->inputs as $name => $type) {
                $inputs[] = sprintf('%s => %s', $this->str($name), $this->type($type));
            }

            $lines[] = sprintf(
                "        %s => new MutationEntry(\n            %s,\n            %s,\n            %s,\n            [\n                %s,\n            ],\n            %s,\n            %s,\n        ),",
                $this->str($mutation->name),
                $this->str($mutation->name),
                $this->str($mutation->kind),
                $this->str($mutation->entity),
                implode(",\n                ", $inputs),
                $this->nullableStr($mutation->method),
                $this->nullableStr($mutation->description),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $entries
     */
    private function block(array $entries): string
    {
        return [] === $entries
            ? '[]'
            : sprintf("[\n%s\n            ]", implode("\n", $entries));
    }

    private function type(GraphQLType $type): string
    {
        return sprintf(
            'new GraphQLType(%s, %s, %s)',
            $this->str($type->name),
            $type->nonNull ? 'true' : 'false',
            $type->list ? 'true' : 'false',
        );
    }

    private function str(string $value): string
    {
        return var_export($value, true);
    }

    private function nullableStr(?string $value): string
    {
        return null === $value ? 'null' : $this->str($value);
    }
}
