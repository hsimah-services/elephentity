<?php

declare(strict_types=1);

namespace Eleph\Schema\Pattern;

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Ir\ConfigParameter;

/**
 * Checks supplied values against declared parameters, and fills in the rest.
 *
 * Shared by patterns and integrations. Both declare parameters and both have someone
 * supply values; only the shape around them differs, and one implementation of "is
 * this value acceptable, what is the default, what is missing" means the two cannot
 * drift apart in what they accept.
 */
final readonly class ConfigValues
{
    /**
     * @param array<string, ConfigParameter> $declared
     * @param array<string, mixed>           $supplied
     * @param list<SpecError>                $errors
     *
     * @return array<string, mixed>
     */
    public function resolve(
        array $declared,
        array $supplied,
        string $subject,
        string $file,
        string $pointer,
        array &$errors,
    ): array {
        $resolved = [];

        foreach ($supplied as $key => $value) {
            $parameter = $declared[$key] ?? null;

            if (null === $parameter) {
                $errors[] = new SpecError(
                    'config.unknown',
                    sprintf(
                        '%s declares no configuration "%s". It accepts: %s.',
                        $subject,
                        $key,
                        [] === $declared ? 'nothing' : implode(', ', array_keys($declared)),
                    ),
                    $file,
                    sprintf('%s/%s', $pointer, $key),
                );

                continue;
            }

            if (!$parameter->accepts($value)) {
                $errors[] = new SpecError(
                    'config.invalidValue',
                    sprintf('Configuration "%s" expects %s.', $key, $parameter->describeExpectation()),
                    $file,
                    sprintf('%s/%s', $pointer, $key),
                );

                continue;
            }

            $resolved[$key] = $value;
        }

        foreach ($declared as $key => $parameter) {
            if (array_key_exists($key, $resolved)) {
                continue;
            }

            if ($parameter->isRequired()) {
                $errors[] = new SpecError(
                    'config.required',
                    sprintf('%s requires configuration "%s": %s.', $subject, $key, $parameter->describeExpectation()),
                    $file,
                    $pointer,
                );

                continue;
            }

            $resolved[$key] = $parameter->hasDefault ? $parameter->default : null;
        }

        ksort($resolved);

        return $resolved;
    }
}
