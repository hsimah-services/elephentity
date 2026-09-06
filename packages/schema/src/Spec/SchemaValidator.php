<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

use Eleph\Schema\Error\SpecError;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Validates spec documents against the JSON Schemas that define the spec format.
 *
 * This is gate one: is the document well-formed? Whether it makes sense — that its
 * edge targets exist, that its patterns apply — is semantic validation's job.
 */
final class SchemaValidator
{
    private const RESOURCE_DIRECTORY = __DIR__ . '/../../resources';

    private readonly Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();

        $resolver = $this->validator->resolver();

        foreach (['common', 'project', 'entity', 'pattern', 'type'] as $name) {
            $resolver?->registerFile(
                sprintf('https://dev.hbla.ke/elephentity/v0/%s.schema.json', $name),
                sprintf('%s/%s.schema.json', self::RESOURCE_DIRECTORY, $name),
            );
        }
    }

    /**
     * @return list<SpecError>
     */
    public function validate(RawSpec $spec): array
    {
        $result = $this->validator->validate(
            Helper::toJSON($spec->data),
            $spec->kind->schemaUri(),
        );

        $error = $result->error();

        if (null === $error) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter())->formatKeyed($error) as $pointer => $messages) {
            // formatKeyed is documented as pointer => list<string>, but is typed
            // loosely enough that static analysis cannot rely on it.
            foreach ((array) $messages as $message) {
                if (!is_string($message)) {
                    continue;
                }

                $errors[] = new SpecError(
                    'spec.invalid',
                    $message,
                    $spec->file,
                    '' === $pointer ? '/' : (string) $pointer,
                );
            }
        }

        return $errors;
    }
}
