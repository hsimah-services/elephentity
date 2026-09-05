<?php

declare(strict_types=1);

namespace PheFr\Schema\Ir;

enum TriggerEvent: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
