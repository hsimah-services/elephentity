<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

use Eleph\Runtime\Catalogue\EntityCatalogue;

final readonly class WriteGate
{
    public function __construct(
        private EntityCatalogue $catalogue,
        private ViewerProvider $viewers,
    ) {
    }

    public function permit(string $entity, ?object $original, WriteContext $context): void
    {
        if ($this->catalogue->writePolicies($entity)->isEmpty()) {
            return;
        }

        $decision = $this->catalogue->writePolicies($entity)->decide(
            $original,
            $context,
            $this->viewers->viewer(),
        );

        if (PolicyOutcome::Allow !== $decision->outcome) {
            throw new AccessDenied($entity, $decision->policy, $decision->reason);
        }
    }
}
