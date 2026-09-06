<?php

declare(strict_types=1);

namespace Eleph\Runtime\Type;

use Eleph\Runtime\Mutation\MutationContext;
use Eleph\Runtime\Verification\Verification;

/**
 * Checks a domain value and turns it back into a stored primitive.
 *
 * verify() returns violations rather than throwing. Throwing would abort on the first
 * invalid field, so a caller submitting fifteen fields fixes one error per round trip;
 * returning lets the unit of work run every verifier up front and reject the whole
 * commit with a complete list.
 *
 * Null never arrives here, as with ReadProcessor.
 *
 * @template TIn of scalar
 * @template TOut
 */
interface WriteProcessor
{
    /**
     * @param TOut $value
     */
    public function verify(mixed $value, MutationContext $context): Verification;

    /**
     * Called only after verify() has passed.
     *
     * @param TOut $value
     *
     * @return TIn
     */
    public function write(mixed $value): mixed;
}
