<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

/**
 * Writes the taxonomy arguments out as PHP that returns them.
 *
 * A plain array, like `PostTypeManifestExporter`: this is WordPress's own
 * `register_taxonomy()` argument shape, passed through unexamined rather than modelled.
 */
final readonly class TaxonomyManifestExporter
{
    /**
     * @param array<string, array<string, mixed>> $taxonomies
     */
    public function export(array $taxonomies): string
    {
        return sprintf(
            <<<'PHP'
                /**
                 * The compiled taxonomies.
                 *
                 * Loaded at boot and handed to `register_taxonomy()` as-is. Deriving these at run
                 * time meant compiling the spec on every request, which is the cost every
                 * manifest here exists to remove.
                 */
                return %s;

                PHP,
            $this->render($taxonomies, 0),
        );
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function render(array $value, int $depth): string
    {
        if ([] === $value) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $lines[] = sprintf(
                '%s%s => %s,',
                $indent,
                var_export($key, true),
                is_array($item) ? $this->render($item, $depth + 1) : var_export($item, true),
            );
        }

        return sprintf("[\n%s\n%s]", implode("\n", $lines), str_repeat('    ', $depth));
    }
}
