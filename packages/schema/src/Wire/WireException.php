<?php

declare(strict_types=1);

namespace Eleph\Schema\Wire;

use RuntimeException;

/**
 * The IR on the wire was not what it claimed to be.
 *
 * Always a hard failure. A builder that half-understands the IR generates code that is
 * subtly wrong and then gets it signed, which is the worst outcome this system has; not
 * building is strictly better. See docs/PLAN.md §15.
 */
final class WireException extends RuntimeException
{
}
