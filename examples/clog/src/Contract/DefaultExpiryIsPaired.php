<?php

declare(strict_types=1);

namespace Clog\Contract;

use Clog\Entity\Enum\ExpiryUnit;
use Clog\Entity\Item\Contract\ItemDefaultExpiryUnitVerifier;
use Clog\Entity\Item\Contract\ItemDefaultExpiryValueVerifier;
use Clog\Entity\Item\ItemMutationContext;
use Eleph\Runtime\Verification\Verification;
use Eleph\Runtime\Verification\Violation;

/**
 * "Both or neither": a default expiry needs a unit and a value, or nothing.
 *
 * One class implementing both field verifiers, because it is one rule. Each half is
 * declared `verify: true` so the framework asks about both, and the context carries
 * the whole pending state — including the other field's new value — so the rule can be
 * stated once and checked from either side.
 *
 * This is the invariant that has nowhere to live in a per-type processor: `ExpiryUnit`
 * knows nothing about the entity it is attached to, let alone the field beside it.
 */
final readonly class DefaultExpiryIsPaired implements ItemDefaultExpiryUnitVerifier, ItemDefaultExpiryValueVerifier
{
    public function verify(ExpiryUnit|int $value, ItemMutationContext $context): Verification
    {
        // Whichever half is being verified, the question is about the pair — and
        // pending() falls back to what the row already holds, so an update that names
        // only one of them is judged against the other as it will actually stand.
        $unit = $context->pendingDefaultExpiryUnit();
        $number = $context->pendingDefaultExpiryValue();

        if ((null === $unit) === (null === $number)) {
            return Verification::ok();
        }

        return Verification::failed(new Violation(
            'item.defaultExpiry.incomplete',
            'A default expiry needs both a unit and a value, or neither.',
        ));
    }
}
