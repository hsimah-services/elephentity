<?php
declare(strict_types=1);

// Produced by elephentity/codegen-php v0.6.1 from the valid fixture with PostStatus backed by int.
namespace App\Elephentity\Enum;

enum PostStatus: int
{
    case Draft = 0;

    case Scheduled = 1;

    case Published = 2;
}
