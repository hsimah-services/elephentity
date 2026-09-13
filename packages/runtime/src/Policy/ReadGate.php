<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

use Eleph\Runtime\Catalogue\EntityCatalogue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ReadGate
{
    public function __construct(
        private EntityCatalogue $catalogue,
        private ViewerProvider $viewers,
        private ?LoggerInterface $log = new NullLogger(),
    ) {
    }

    public function guards(string $entity): bool
    {
        return !$this->catalogue->readPolicies($entity)->isEmpty();
    }

    public function permit(string $entity, object $object): object
    {
        if (!$this->guards($entity)) {
            return $object;
        }

        $decision = $this->catalogue->readPolicies($entity)->decide($object, $this->viewers->viewer());

        if (PolicyOutcome::Allow !== $decision->outcome) {
            throw new AccessDenied($entity, $decision->policy, $decision->reason);
        }

        return $object;
    }

    /**
     * @param list<object> $objects
     * @return list<object>
     */
    public function retain(string $entity, array $objects): array
    {
        if (!$this->guards($entity)) {
            return $objects;
        }

        $viewer = $this->viewers->viewer();
        /** @var list<object> $retained */
        $retained = [];

        foreach ($objects as $object) {
            $decision = $this->catalogue->readPolicies($entity)->decide($object, $viewer);

            if (PolicyOutcome::Allow === $decision->outcome) {
                $retained[] = $object;
                continue;
            }

            $this->log?->debug('Read policy filtered an entity.', [
                'entity' => $entity,
                'policy' => $decision->policy,
                'reason' => $decision->reason,
            ]);
        }

        return array_values($retained);
    }
}
