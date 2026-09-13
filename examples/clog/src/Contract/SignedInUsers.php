<?php

declare(strict_types=1);

namespace Clog\Contract;

use Clog\Entity\Pattern\ClogPost\ClogPost;
use Clog\Entity\Pattern\ClogPost\Contract\ClogPostSignedInReadPolicy;
use Eleph\Runtime\Policy\PolicyDecision;
use Eleph\Runtime\Policy\Viewer;

final readonly class SignedInUsers implements ClogPostSignedInReadPolicy
{
    public function decide(ClogPost $entity, Viewer $viewer): PolicyDecision
    {
        return $viewer->isAuthenticated()
            ? PolicyDecision::allow()
            : PolicyDecision::deny('Sign in to read this entity.');
    }
}
