<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * What a pattern's configuration parameter may hold.
 *
 * A narrower vocabulary than field primitives, and a wider one in exactly one respect:
 * `List` is permitted here. Configuration is build-time data that never becomes a
 * column, so the reason a field cannot hold a list — that it would be an unindexable
 * blob — simply does not apply.
 */
enum ConfigType: string
{
    case String = 'string';
    case Int = 'int';
    case Bool = 'bool';
    case Enum = 'enum';
    case List = 'list';
}
