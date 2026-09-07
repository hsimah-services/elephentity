<?php

declare(strict_types=1);

namespace Eleph\Codegen\Protocol;

use RuntimeException;

/**
 * A builder said something the protocol does not allow.
 *
 * Always fatal to the build. The alternative is generating from a half-understood
 * exchange and signing the result. See docs/PLAN.md §15.
 */
final class ProtocolException extends RuntimeException
{
}
