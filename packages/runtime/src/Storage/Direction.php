<?php

declare(strict_types=1);

namespace PheFr\Runtime\Storage;

enum Direction: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
