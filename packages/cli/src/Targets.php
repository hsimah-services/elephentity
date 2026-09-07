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
 * Today every target is in-process. When a target becomes an external program, this is
 * the one place that learns how to find it.
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
