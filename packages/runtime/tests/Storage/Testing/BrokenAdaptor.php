<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\Storage\Testing;

use Eleph\Runtime\Capability\Capabilities;
use Eleph\Runtime\Capability\Capability;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\Page;
use Eleph\Runtime\Storage\Record;
use Eleph\Runtime\Storage\StorageAdaptor;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Storage\Write\WriteResult;

/**
 * An adaptor that type-checks against the port and satisfies none of it — proof that
 * `AdaptorConformance` actually exercises the contract rather than the interface.
 */
final class BrokenAdaptor implements StorageAdaptor
{
    public function capabilities(): Capabilities
    {
        return new Capabilities('broken', Capability::Transactions);
    }

    /**
     * Never finds what was just written — the one thing every adaptor exists to do.
     */
    public function get(string $entity, EntityId $id): ?Record
    {
        return null;
    }

    public function getMany(string $entity, array $ids): array
    {
        return [];
    }

    /**
     * @return Page<Record>
     */
    public function query(Criteria $criteria): Page
    {
        return new Page([]);
    }

    public function count(Criteria $criteria): int
    {
        return 0;
    }

    public function write(WriteBatch $batch): WriteResult
    {
        $result = new WriteResult();

        foreach ($batch->operations as $operation) {
            if ($operation instanceof Insert) {
                $result->assign($operation->pendingId(), EntityId::of(1));
            }
        }

        return $result;
    }

    public function transaction(callable $work): mixed
    {
        return $work();
    }
}
