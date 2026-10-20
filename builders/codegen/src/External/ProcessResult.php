<?php

declare(strict_types=1);

namespace Eleph\Codegen\External;

/**
 * What a builder left behind when it exited.
 */
final readonly class ProcessResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
    ) {
    }
}
