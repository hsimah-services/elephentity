<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Manifest;

/**
 * A GraphQL type reference: a name, plus whether it is non-null and whether it is a
 * list.
 *
 * Kept as a value object rather than a string so the registrar can build WPGraphQL's
 * nested array form without parsing anything.
 */
final readonly class GraphQLType
{
    public function __construct(
        public string $name,
        public bool $nonNull = false,
        public bool $list = false,
    ) {
    }

    /**
     * WPGraphQL's shape: a bare name, or nested ['non_null' => …] / ['list_of' => …].
     *
     * @return string|array<string, mixed>
     */
    public function toConfig(): string|array
    {
        $type = $this->list ? ['list_of' => $this->name] : $this->name;

        return $this->nonNull ? ['non_null' => $type] : $type;
    }
}
