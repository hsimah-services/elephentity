<?php

declare(strict_types=1);

namespace Eleph\Cli;

use Eleph\Codegen\Php\PhpTarget;
use Eleph\Codegen\Target;

/**
 * Which code generators this installation offers.
 *
 * The CLI is the composition root — the only layer that knows which packages are
 * present — so it assembles the registry and hands it down, exactly as it already does
 * for integrations. `packages/codegen` runs whatever arrives without naming one.
 *
 * These are the built-ins, run in process. A target that declares a `builder` in
 * eleph.json is an external program instead, found by `Builders` and reached over the
 * protocol — `GenerateCommand` picks between the two and nothing downstream can tell
 * which it got.
 */
final readonly class Targets
{
    /**
     * @return array<string, Target>
     */
    public static function registry(): array
    {
        $targets = [new PhpTarget()];
        $registry = [];

        foreach ($targets as $target) {
            $registry[$target->name()] = $target;
        }

        return $registry;
    }
}
