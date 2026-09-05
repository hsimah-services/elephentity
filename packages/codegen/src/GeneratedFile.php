<?php

declare(strict_types=1);

namespace PheFr\Codegen;

/**
 * One file the generator owns, addressed relative to the output directory.
 */
final readonly class GeneratedFile
{
    public function __construct(
        public string $relativePath,
        /** Everything after the signed header. */
        public string $body,
    ) {
    }
}
