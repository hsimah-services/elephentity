<?php

declare(strict_types=1);

namespace Eleph\Schema\Ir;

/**
 * PreCommit changes pending state before verification and persistence. PostCommit runs
 * after persistence; any writes it initiates are independent mutations.
 */
enum SideEffectPhase: string
{
    case PreCommit = 'preCommit';
    case PostCommit = 'postCommit';

    public function allowsMutation(): bool
    {
        return self::PreCommit === $this;
    }
}
