<?php

declare(strict_types=1);

namespace Eleph\Schema;

use Eleph\Schema\Spec\SpecKind;

/**
 * Where the specs live.
 *
 * Convention over configuration: one root holding entities/, patterns/ and types/.
 */
final readonly class SpecSource
{
    public function __construct(public string $root)
    {
    }

    public function directoryFor(SpecKind $kind): string
    {
        return rtrim($this->root, '/') . '/' . $kind->directory();
    }
}
