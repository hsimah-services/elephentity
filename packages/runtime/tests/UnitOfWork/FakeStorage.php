<?php

declare(strict_types=1);

namespace Eleph\Runtime\Tests\UnitOfWork;

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
use Throwable;

final class FakeStorage implements StorageAdaptor
{
    /** @var list<WriteBatch> */
    public array $batches = [];

    /** @var list<string> */
    public array $log = [];

    public int $nextId = 1;

    /** @var array<string, list<Record>> Keyed by entity, so a query answers for the entity it asked about. */
    public array $records = [];

    public int $countResult = 0;

    public function capabilities(): Capabilities
    {
        return new Capabilities('fake', Capability::Transactions);
    }

    public function get(string $entity, EntityId $id): ?Record
    {
        return null;
    }

    public function getMany(string $entity, array $ids): array
    {
        return [];
    }

    public function query(Criteria $criteria): Page
    {
        return new Page($this->records[$criteria->entity] ?? []);
    }

    public function count(Criteria $criteria): int
    {
        return $this->countResult;
    }

    public function write(WriteBatch $batch): WriteResult
    {
        $this->batches[] = $batch;

        $result = new WriteResult();

        foreach ($batch->operations as $operation) {
            $this->log[] = sprintf('%s %s', $this->name($operation), $operation->entity());

            if ($operation instanceof Insert) {
                $result->assign($operation->pendingId(), EntityId::of($this->nextId++));
            }
        }

        return $result;
    }

    public function transaction(callable $work): mixed
    {
        $this->log[] = 'begin';

        try {
            $value = $work();
        } catch (Throwable $exception) {
            $this->log[] = 'rollback';

            throw $exception;
        }

        $this->log[] = 'commit';

        return $value;
    }

    private function name(object $operation): string
    {
        $parts = explode('\\', $operation::class);

        return strtolower(end($parts));
    }
}
