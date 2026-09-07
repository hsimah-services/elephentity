<?php

declare(strict_types=1);

namespace Eleph\WPGraphQL\Tests;

/**
 * Stands in for the enum the generator emits from types/PostStatus.yml.
 *
 * The registrar never sees the generated class — it calls an accessor and encodes what
 * comes back — so a hand-written enum with the same backing values proves the same
 * thing without dragging codegen into a wpgraphql test.
 */
enum FixtureStatus: string
{
    case Draft = 'draft';

    case Scheduled = 'scheduled';

    case Published = 'published';
}
