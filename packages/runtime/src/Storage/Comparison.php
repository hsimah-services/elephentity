<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

enum Comparison: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case In = 'in';
    case NotIn = 'notIn';
    case Contains = 'contains';
    case StartsWith = 'startsWith';
    case IsNull = 'isNull';
    case IsNotNull = 'isNotNull';

    /** Whether this comparison reads its value, or stands alone. */
    public function takesValue(): bool
    {
        return self::IsNull !== $this && self::IsNotNull !== $this;
    }
}
