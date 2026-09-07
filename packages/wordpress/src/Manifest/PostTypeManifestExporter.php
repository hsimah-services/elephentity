<?php

declare(strict_types=1);

namespace Eleph\WordPress\Manifest;

/**
 * Writes the post type arguments out as PHP that returns them.
 *
 * A plain array rather than the constructor calls the other two manifests use, because
 * this is not a shape we own: it is WordPress's argument array, passed through
 * unexamined, and inventing value objects for `show_in_menu` would be modelling
 * somebody else's API for no gain.
 */
final readonly class PostTypeManifestExporter
{
    /**
     * @param array<string, array<string, mixed>> $types
     */
    public function export(array $types): string
    {
        return sprintf(
            <<<'PHP'
                /**
                 * The compiled post types.
                 *
                 * Loaded at boot and handed to `register_post_type()` as-is. Deriving these at
                 * run time meant compiling the spec on every request, which is the cost every
                 * manifest here exists to remove.
                 */
                return %s;

                PHP,
            $this->render($types, 0),
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
