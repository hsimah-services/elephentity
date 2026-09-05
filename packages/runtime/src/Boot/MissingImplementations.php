<?php

declare(strict_types=1);

namespace PheFr\Runtime\Boot;

use RuntimeException;

/**
 * Raised at boot when generated interfaces have no implementations.
 *
 * The spec declares the bespoke unit, the generator emits its interface, and the
 * application supplies the class. Until it does, the application does not start.
 *
 * Boot time rather than call time, so a missing handler cannot lurk in production
 * until the wrong request arrives; and boot rather than generate time, so codegen
 * stays a pure function of the spec and never has to scan application code.
 *
 * Every missing implementation is listed at once — fixing them one exception at a
 * time would be needlessly slow.
 */
final class MissingImplementations extends RuntimeException
{
    /**
     * @param list<string> $interfaces
     */
    public function __construct(public readonly array $interfaces)
    {
        parent::__construct(sprintf(
            "PheFr cannot start: %d generated interface(s) have no implementation.\n  %s",
            count($interfaces),
            implode("\n  ", $interfaces),
        ));
    }
}
