<?php

declare(strict_types=1);

namespace Eleph\Runtime\Catalogue;

use Eleph\Runtime\Boot\MissingImplementations;
use Psr\Container\ContainerInterface;

/**
 * Refuses to start while any generated interface has no implementation.
 *
 * The catalogue already knows every contract the spec produced, so this needs nothing
 * from the application but its container — no registration, no list to maintain, no
 * chance of the list drifting from what was generated.
 *
 * Boot time is the point. Later than generation, so codegen stays a pure function of
 * the spec and never scans source; earlier than the first call, so a missing handler
 * cannot lurk until the wrong request arrives.
 */
final readonly class BootCheck
{
    public function __construct(
        private EntityCatalogue $catalogue,
        private ContainerInterface $container,
    ) {
    }

    /**
     * @throws MissingImplementations naming every one at once, because fixing them one
     *                                restart at a time is needlessly slow.
     */
    public function run(): void
    {
        $missing = [];

        foreach ($this->catalogue->contracts() as $contract) {
            if (!$this->container->has($contract)) {
                $missing[] = $contract;
            }
        }

        if ([] !== $missing) {
            throw new MissingImplementations($missing);
        }
    }
}
