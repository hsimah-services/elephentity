<?php

declare(strict_types=1);

namespace Eleph\Runtime\Trigger;

enum TriggerEvent: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
}
