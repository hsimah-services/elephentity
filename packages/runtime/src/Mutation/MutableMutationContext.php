<?php

declare(strict_types=1);

namespace Eleph\Runtime\Mutation;

/** Pending state that actions and pre-commit side effects may change. */
interface MutableMutationContext extends MutationContext, MutationBuffer
{
}
