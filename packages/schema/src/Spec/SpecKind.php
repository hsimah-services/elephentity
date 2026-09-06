<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

/**
 * The three kinds of spec document, discriminated by their required root key.
 */
enum SpecKind: string
{
    case Project = 'project';
    case Entity = 'entity';
    case Pattern = 'pattern';
    case Type = 'type';

    public function schemaUri(): string
    {
        return sprintf('https://dev.hbla.ke/elephentity/v0/%s.schema.json', $this->value);
    }

    /**
     * The directory this kind is found in, or null for the one that is a single file.
     */
    public function directory(): ?string
    {
        return match ($this) {
            self::Project => null,
            self::Entity => 'entities',
            self::Pattern => 'patterns',
            self::Type => 'types',
        };
    }
}
