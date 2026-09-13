<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

enum PolicyOutcome: string
{
    case Allow = 'allow';
    case Skip = 'skip';
    case Deny = 'deny';
}
