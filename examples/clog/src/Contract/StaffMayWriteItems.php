<?php

declare(strict_types=1);

namespace Clog\Contract;

use Clog\Entity\Item\Contract\ItemStaffWritePolicy;
use Clog\Entity\Item\Item;
use Clog\Entity\Item\ItemWriteContext;
use Eleph\Runtime\Policy\PolicyDecision;
use Eleph\Runtime\Policy\Viewer;

final readonly class StaffMayWriteItems implements ItemStaffWritePolicy
{
    public function decide(?Item $entity, ItemWriteContext $context, Viewer $viewer): PolicyDecision
    {
        return $viewer->can('edit_posts')
            ? PolicyDecision::allow()
            : PolicyDecision::deny('Staff access is required to write items.');
    }
}
