<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * An action's declared blast radius.
 *
 * The action does not receive the Mutator. It receives a narrow generated context
 * exposing only these members, so it physically cannot touch a field it did not
 * declare and PHPStan enforces that. Blast radius becomes reviewable in the yaml
 * rather than discoverable only by reading the implementation.
 */
final readonly class ActionWrites
{
    /**
     * @param list<string> $fields
     * @param list<string> $edges
     */
    public function __construct(
        public array $fields = [],
        public array $edges = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields && [] === $this->edges;
    }
}
