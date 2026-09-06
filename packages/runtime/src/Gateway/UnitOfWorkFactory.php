<?php

declare(strict_types=1);

namespace Eleph\Runtime\Gateway;

use Eleph\Runtime\Catalogue\EntityCatalogue;
use Eleph\Runtime\Storage\DeletionRule;
use Eleph\Runtime\Storage\DeletionRules;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Type\ProcessorRegistry;
use Eleph\Runtime\UnitOfWork\DeletionPlanner;
use Eleph\Runtime\UnitOfWork\TriggerDispatcher;
use Eleph\Runtime\UnitOfWork\UnitOfWork;
use Eleph\Runtime\UnitOfWork\ValueEncoder;
use Eleph\Runtime\UnitOfWork\VerificationPipeline;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a unit of work per commit.
 *
 * One per commit rather than one shared: a unit of work accumulates state and is
 * emptied by committing, so reusing one across requests would mean deciding what
 * happens to a half-built commit that was never finished.
 *
 * Assembling it means reading the catalogue for verifiers, triggers and deletion
 * rules — all generated, all derived from the spec.
 */
final readonly class UnitOfWorkFactory implements DeletionRules
{
    public function __construct(
        private StorageAdaptor $storage,
        private EntityCatalogue $catalogue,
        private ProcessorRegistry $processors,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function create(): UnitOfWork
    {
        $verifiers = [];
        $triggers = [];

        foreach ($this->catalogue->entities() as $entity) {
            $verifiers[$entity] = $this->catalogue->verifiers($entity);
            $triggers[$entity] = $this->catalogue->triggers($entity);
        }

        $fieldTypes = $this->catalogue->fieldTypes();

        return new UnitOfWork(
            $this->storage,
            new VerificationPipeline($verifiers, $fieldTypes, $this->processors),
            new ValueEncoder($fieldTypes, $this->processors),
            new TriggerDispatcher($triggers, $this->logger),
            planner: new DeletionPlanner($this->storage, $this),
        );
    }

    /**
     * @return list<DeletionRule>
     */
    public function for(string $entity): array
    {
        return $this->catalogue->deletionRules($entity);
    }
}
