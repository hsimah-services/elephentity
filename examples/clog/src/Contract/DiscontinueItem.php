<?php

declare(strict_types=1);

namespace Clog\Contract;

use Clog\Entity\Item\Contract\ItemDiscontinueAction;
use Clog\Entity\Item\ItemDiscontinueContext;

/**
 * `Item.discontinue` from the spec, implemented.
 *
 * The write itself is one line: clear the barcode so a scan stops resolving to a
 * retired item. `$reason` is not stored anywhere — it exists for
 * `StaffMayWriteItems` to read off `ItemWriteContext::discontinue()`, not for the
 * mutation itself.
 */
final readonly class DiscontinueItem implements ItemDiscontinueAction
{
    public function handle(ItemDiscontinueContext $context, string $reason): void
    {
        $context->setBarcode(null);
    }
}
