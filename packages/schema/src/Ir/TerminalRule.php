<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

enum TerminalRule: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
