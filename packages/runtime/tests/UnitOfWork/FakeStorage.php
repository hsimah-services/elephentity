<?php

declare(strict_types=1);

namespace PheFr\Runtime\Tests\UnitOfWork;

use PheFr\Runtime\Capability\Capabilities;
use PheFr\Runtime\Capability\Capability;
use PheFr\Runtime\Identity\EntityId;
use PheFr\Runtime\Storage\Criteria;
use PheFr\Runtime\Storage\Page;
use PheFr\Runtime\Storage\Record;
use PheFr\Runtime\Storage\StorageAdaptor;
use PheFr\Runtime\Storage\Write\Insert;
use PheFr\Runtime\Storage\Write\WriteBatch;
use PheFr\Runtime\Storage\Write\WriteResult;
use Throwable;

final class FakeStorage implements StorageAdaptor
{
    /** @var list<WriteBatch> */
    public array $batches = [];

    /** @var list<string> */
    public array $log = [];

    public int $nextId = 1;

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
        /** @var Page<Record> $page */
        $page = Page::empty();

        return $page;
    }

    public function count(Criteria $criteria): int
    {
        return 0;
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
