<?php

declare(strict_types=1);

namespace Eleph\Runtime\Verification;

/**
 * A violation once the unit of work has attached where it happened.
 *
 * Verifiers do not know their own field — a shared processor like Money is used by
 * many — so the path is added here, at the point that does know.
 */
final readonly class FieldViolation
{
    public function __construct(
        public string $entity,
        public string $field,
        public Violation $violation,
    ) {
    }

    public function path(): string
    {
        return sprintf('%s.%s', $this->entity, $this->field);
    }

    public function describe(): string
    {
        return sprintf('%s: %s [%s]', $this->path(), $this->violation->message, $this->violation->code);
    }
}
