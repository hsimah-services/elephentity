<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

enum WriteOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Action = 'action';
}
