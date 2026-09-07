<?php

declare(strict_types=1);

namespace Eleph\Cli;

use JsonException;
use RuntimeException;

/**
 * eleph.json — where the specs are and which targets the project generates.
 *
 * Configuration, deliberately separate from specification. Namespaces live here so
 * that renaming one is a config change rather than an edit to every entity yaml.
 *
 * The file names its targets and nothing more about them than where their output goes;
 * each target reads and validates its own settings. Problems are accumulated and
 * reported together, so a file missing three keys takes one run to fix rather than
 * three.
 */
final readonly class ProjectConfig
{
    public const FILENAME = 'eleph.json';

    /**
     * @param array<string, TargetConfig> $targets
     */
    public function __construct(
        public string $specDirectory,
        public array $targets,
    ) {
    }

    public static function load(string $directory): self
    {
        $path = rtrim($directory, '/') . '/' . self::FILENAME;

        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No %s in %s. It needs "spec" and a "targets" block.',
                self::FILENAME,
                $directory,
            ));
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('%s is not valid JSON: %s', $path, $exception->getMessage()));
        }

        $problems = [];

        $spec = $data['spec'] ?? null;

        if (!is_string($spec) || '' === $spec) {
            $problems[] = '"spec" must be a non-empty string.';
        }

        $targets = self::targetsIn($data, $problems);

        if ([] !== $problems) {
            throw new RuntimeException(sprintf(
                "%s is not usable:\n  %s",
                $path,
                implode("\n  ", $problems),
            ));
        }

        /** @var string $spec */
        return new self($spec, $targets);
    }

    public function target(string $name): ?TargetConfig
    {
        return $this->targets[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $problems
     *
     * @return array<string, TargetConfig>
     */
    private static function targetsIn(array $data, array &$problems): array
    {
        $block = $data['targets'] ?? null;

        if (!is_array($block) || [] === $block) {
            $problems[] = '"targets" must be an object with at least one target in it.';

            return [];
        }

        $targets = [];

        /** @var mixed $settings */
        foreach ($block as $name => $settings) {
            if (!is_string($name)) {
                $problems[] = 'Every target must be keyed by name.';

                continue;
            }

            if (!is_array($settings)) {
                $problems[] = sprintf('Target "%s" must be an object.', $name);

                continue;
            }

            $output = $settings['output'] ?? null;

            if (!is_string($output) || '' === $output) {
                $problems[] = sprintf('Target "%s" must set "output" to a non-empty string.', $name);

                continue;
            }

            /** @var array<string, mixed> $settings */
            $targets[$name] = new TargetConfig($name, $output, $settings);
        }

        return $targets;
    }
}
