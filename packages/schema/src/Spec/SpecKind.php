<?php

declare(strict_types=1);

namespace PheFr\Schema\Spec;

/**
 * The three kinds of spec document, discriminated by their required root key.
 */
enum SpecKind: string
{
    case Entity = 'entity';
    case Pattern = 'pattern';
    case Type = 'type';

    public function schemaUri(): string
    {
        return sprintf('https://phefr.dev/schema/%s.json', $this->value);
    }

    public function directory(): string
    {
        return match ($this) {
            self::Entity => 'entities',
            self::Pattern => 'patterns',
            self::Type => 'types',
        };
    }
}
