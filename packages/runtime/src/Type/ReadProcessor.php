<?php

declare(strict_types=1);

namespace PheFr\Runtime\Type;

/**
 * Turns a stored primitive into its domain type.
 *
 * The read half of a value type, mirroring the Entity/Mutator split at the type level.
 *
 * Null never arrives here: a nullable field holding null short-circuits, so no
 * processor needs to open with the same null check.
 *
 * PHP has no runtime generics, so the signature is mixed and the real types live in
 * the annotations, where PHPStan enforces them.
 *
 * @template TIn of scalar
 * @template TOut
 */
interface ReadProcessor
{
    /**
     * @param TIn $value
     *
     * @return TOut
     */
    public function read(mixed $value): mixed;
}
