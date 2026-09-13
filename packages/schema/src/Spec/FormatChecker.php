<?php

declare(strict_types=1);

namespace Eleph\Schema\Spec;

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\SpecSource;

/**
 * Checks that specs are written in canonical form.
 *
 * Reports rather than rewrites, and that is deliberate. PHP's YAML parsers discard
 * comments, so a formatter built on parse-and-dump would silently delete every
 * explanatory note an author had written — a far worse outcome than an ordering
 * nit. Until there is a comment-preserving emitter, saying precisely what is out of
 * order and letting a human move two lines is the honest trade.
 *
 * Each report names the file, the path within it, and the order expected, so acting
 * on one is mechanical even though it is manual.
 */
final readonly class FormatChecker
{
    public function __construct(
        private SpecLoader $loader = new SpecLoader(),
        private CanonicalOrder $order = new CanonicalOrder(),
    ) {
    }

    /**
     * @return list<SpecError>
     */
    public function check(SpecSource $source): array
    {
        $loaded = $this->loader->load($source);
        $errors = $loaded['errors'];

        foreach ($loaded['specs'] as $spec) {
            foreach ($this->checkDocument($spec) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @return list<SpecError>
     */
    private function checkDocument(RawSpec $spec): array
    {
        $errors = [];

        $this->checkMapping($spec, $spec->kind->value, $spec->data, '', $errors);

        $sections = [
            'fields' => 'field',
            'edges' => 'edge',
            'queries' => 'query',
            'actions' => 'action',
            'triggers' => 'trigger',
            'readPolicies' => 'policy',
            'writePolicies' => 'policy',
        ];

        $parameters = $spec->data['config'] ?? null;

        if (is_array($parameters)) {
            foreach ($parameters as $name => $parameter) {
                if (is_array($parameter) && is_string($name)) {
                    /** @var array<string, mixed> $parameter */
                    $this->checkMapping(
                        $spec,
                        'configParameter',
                        $parameter,
                        sprintf('/config/%s', $name),
                        $errors,
                    );
                }
            }
        }

        // The project's storage block holds different keys from an entity's.
        $storageShape = SpecKind::Project === $spec->kind ? 'projectStorage' : 'storage';

        foreach ([$storageShape, 'requires'] as $shape) {
            $key = 'projectStorage' === $shape ? 'storage' : $shape;
            $nested = $spec->data[$key] ?? null;

            if (is_array($nested)) {
                /** @var array<string, mixed> $nested */
                $this->checkMapping($spec, $shape, $nested, '/' . $key, $errors);
            }
        }

        $policies = $spec->data['policies'] ?? null;
        $terminalRule = is_array($policies) ? ($policies['terminalRule'] ?? null) : null;

        if (is_array($terminalRule)) {
            /** @var array<string, mixed> $terminalRule */
            $this->checkMapping($spec, 'terminalRule', $terminalRule, '/policies/terminalRule', $errors);
        }

        foreach ($sections as $section => $shape) {
            $members = $spec->data[$section] ?? null;

            if (!is_array($members)) {
                continue;
            }

            foreach ($members as $name => $member) {
                if (!is_array($member) || !is_string($name)) {
                    continue;
                }

                $path = sprintf('/%s/%s', $section, $name);

                /** @var array<string, mixed> $member */
                $this->checkMapping($spec, $shape, $member, $path, $errors);

                foreach (['inverse', 'returns', 'writes'] as $nestedKey) {
                    $nested = $member[$nestedKey] ?? null;

                    if (is_array($nested)) {
                        /** @var array<string, mixed> $nested */
                        $this->checkMapping($spec, $nestedKey, $nested, $path . '/' . $nestedKey, $errors);
                    }
                }

                $arguments = $member['args'] ?? null;

                if (!is_array($arguments)) {
                    continue;
                }

                foreach ($arguments as $argument => $definition) {
                    if (is_array($definition) && is_string($argument)) {
                        /** @var array<string, mixed> $definition */
                        $this->checkMapping(
                            $spec,
                            'argument',
                            $definition,
                            sprintf('%s/args/%s', $path, $argument),
                            $errors,
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param list<SpecError>      $errors
     */
    private function checkMapping(
        RawSpec $spec,
        string $shape,
        array $mapping,
        string $path,
        array &$errors,
    ): void {
        $keys = array_values(array_filter(array_keys($mapping), is_string(...)));

        if ($this->order->isOrdered($shape, $keys)) {
            return;
        }

        $errors[] = new SpecError(
            'format.keyOrder',
            sprintf(
                'Keys are out of canonical order. Expected: %s.',
                implode(', ', $this->order->sort($shape, $keys)),
            ),
            $spec->file,
            '' === $path ? '/' : $path,
        );
    }
}
