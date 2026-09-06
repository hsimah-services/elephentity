<?php

declare(strict_types=1);

namespace Eleph\Schema\Error;

use Eleph\Schema\Ir\Schema;
use LogicException;

/**
 * Either a compiled schema or the complete list of reasons there isn't one.
 */
final readonly class CompilationResult
{
    /**
     * @param list<SpecError> $errors
     */
    private function __construct(
        public ?Schema $schema,
        public array $errors,
    ) {
    }

    public static function success(Schema $schema): self
    {
        return new self($schema, []);
    }

    /**
     * @param list<SpecError> $errors
     */
    public static function failure(array $errors): self
    {
        if ([] === $errors) {
            throw new LogicException('A failed compilation must carry at least one error.');
        }

        return new self(null, $errors);
    }

    public function isSuccess(): bool
    {
        return null !== $this->schema;
    }

    /**
     * @throws LogicException when the compilation failed; check isSuccess() first.
     */
    public function schema(): Schema
    {
        if (null === $this->schema) {
            throw new LogicException('Compilation failed; there is no schema to read.');
        }

        return $this->schema;
    }
}
