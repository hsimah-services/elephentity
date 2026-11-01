<?php

declare(strict_types=1);

namespace Eleph\GraphQL\Manifest;

/**
 * One GraphQL mutation.
 *
 * Four kinds, all derived: create and update from the settable fields, delete (an id and
 * nothing else, withheld by an entity's `delete: false`), and one per declared action. An action's inputs are its declared arguments — not its writes,
 * which are what it is permitted to change rather than what the caller supplies.
 */
final readonly class MutationEntry
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const ACTION = 'action';

    /**
     * @param array<string, GraphQLType> $inputs Keyed by input field name.
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $entity,
        public array $inputs,
        /** The mutator method to call, for an action. */
        public ?string $method = null,
        public ?string $description = null,
    ) {
    }
}
