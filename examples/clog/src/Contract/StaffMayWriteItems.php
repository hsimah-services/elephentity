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
        if (!$viewer->can('edit_posts')) {
            return PolicyDecision::deny('Staff access is required to write items.');
        }

        // The typed accessor for an action not currently running returns null, so
        // this only fires for a discontinue — reading it is the point of the
        // example: a write policy inspecting an action's argument through
        // ItemWriteContext, not just the operation and the entity.
        $discontinuing = $context->discontinue();

        if (null !== $discontinuing && '' === trim($discontinuing->reason)) {
            return PolicyDecision::deny('Discontinuing an item requires a reason.');
        }

        return PolicyDecision::allow();
    }
}
