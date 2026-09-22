<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

enum SideEffectEvent: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
