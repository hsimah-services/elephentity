<?php

declare(strict_types=1);

namespace Eleph\Schema\Pattern;

use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Spec\ParsedSections;

/**
 * A reusable fragment of an entity spec.
 */
final readonly class PatternDefinition
{
    /**
     * @param list<string>                    $uses    Patterns this pattern itself pulls in.
     * @param array<string, string>           $storage Partial storage keys this pattern contributes.
     * @param array<string, ConfigParameter>  $config  Parameters entities may supply.
     */
    public function __construct(
        public string $name,
        public string $sourceFile,
        public ParsedSections $sections,
        public array $uses = [],
        public ?string $requiresDriver = null,
        public array $storage = [],
        public array $config = [],
        public ?string $description = null,
    ) {
    }
}
