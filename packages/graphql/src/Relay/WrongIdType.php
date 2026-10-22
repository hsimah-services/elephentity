<?php

declare(strict_types=1);

namespace Eleph\GraphQL\Relay;

use GraphQL\Error\ClientAware;
use InvalidArgumentException;

/**
 * A global id for the wrong type, which is the client's mistake and says so.
 *
 * webonyx shows a client only the messages of exceptions that are ClientAware and
 * replaces the rest with "Internal server error". It stays an InvalidArgumentException
 * so a caller that already catches one keeps working.
 */
final class WrongIdType extends InvalidArgumentException implements ClientAware
{
    public function isClientSafe(): bool
    {
        return true;
    }

    public function getCategory(): string
    {
        return 'user';
    }
}
