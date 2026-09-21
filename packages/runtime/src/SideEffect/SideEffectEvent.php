<?php

declare(strict_types=1);

namespace Eleph\Runtime\SideEffect;

enum SideEffectEvent: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
