<?php

declare(strict_types=1);

namespace Eleph\Runtime\Gateway;

use Eleph\Runtime\Catalogue\EntityCatalogue;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\ActionCall;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Mutation\MutationResult;
use Eleph\Runtime\Policy\AccessDenied;
use Eleph\Runtime\Policy\PendingWrite;
use Eleph\Runtime\Policy\ReadGate;
use Eleph\Runtime\Policy\WriteGate;
use Eleph\Runtime\Query\CachingEdgeLoader;
use Eleph\Runtime\Query\EntityQuery;
use Eleph\Runtime\Query\Hydrator;
use Eleph\Runtime\Query\HydratorRegistry;
use Eleph\Runtime\Query\LazyEntityQuery;
use Eleph\Runtime\Query\Queries;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\StorageAdaptor;
use InvalidArgumentException;
use RuntimeException;

/**
 * The assembled framework, and the one thing an application holds.
 *
 * Every layer below is separately testable and separately useless: an adaptor with no
 * hydrators, a unit of work with no verifiers. This is where they meet.
 *
 * Each write commits. A protocol request is a transaction boundary, and holding a unit
 * of work open across requests would mean deciding when it closes — a question with no
 * good answer in a shared-nothing runtime like PHP.
 */
final readonly class Runtime implements EntityGateway, HydratorRegistry
{
    public function __construct(
        private StorageAdaptor $storage,
        private EntityCatalogue $catalogue,
        private UnitOfWorkFactory $units,
        private ReadGate $reads,
        private WriteGate $writes,
    ) {
    }

    public function find(string $entity, EntityId $id): ?object
    {
        $object = $this->load($entity, $id);

        return null === $object ? null : $this->reads->permit($entity, $object);
    }

    public function all(string $entity): EntityQuery
    {
        return new LazyEntityQuery(
            $this->storage,
            $this->get($entity),
            $this->edges(),
            Criteria::for($entity),
            $this->reads,
        );
    }

    public function runQuery(string $entity, string $query, array $args): EntityQuery
    {
        $finder = $this->catalogue->finder($entity);

        if (!method_exists($finder, $query)) {
            throw new RuntimeException(sprintf(
                '%s declares no query "%s". The finder is generated from the spec, so this is a stale caller.',
                $entity,
                $query,
            ));
        }

        /** @var EntityQuery<object> $result */
        $result = $finder->{$query}(...array_values($this->named($entity, $query, $args)));

        return $result;
    }

    public function create(string $entity, array $input): MutationResult
    {
        $target = new PendingId($entity);
        $mutation = new Mutation($entity, $target, actions: [new ActionCall('create', $input)]);

        $this->writes->permit($entity, null, PendingWrite::create($entity, $mutation, $input));
        $this->catalogue->apply($entity, $mutation, $input);

        $work = $this->units->create();
        $work->register($mutation);

        return $this->result($entity, $work->commit()->idFor($target));
    }

    public function update(string $entity, EntityId $id, array $input): MutationResult
    {
        $existing = $this->load($entity, $id);

        if (null === $existing) {
            throw new RuntimeException(sprintf('There is no %s with id %s.', $entity, $id));
        }

        $mutation = new Mutation($entity, $id, $this->valuesOf($entity, $existing), [new ActionCall('update', $input)], $existing);

        $this->writes->permit($entity, $existing, PendingWrite::update($entity, $mutation, $input));
        $this->catalogue->apply($entity, $mutation, $input);

        $work = $this->units->create();
        $work->register($mutation);
        $work->commit();

        return $this->result($entity, $id);
    }

    public function delete(string $entity, EntityId $id): void
    {
        $existing = $this->load($entity, $id);

        if (null === $existing) {
            throw new RuntimeException(sprintf('There is no %s with id %s.', $entity, $id));
        }

        $this->writes->permit($entity, $existing, PendingWrite::delete($entity));

        $work = $this->units->create();
        $work->delete(new Deletion($entity, $id));
        $work->commit();
    }

    public function runAction(string $entity, string $action, EntityId $id, array $args): MutationResult
    {
        return $this->runActions($entity, $id, [new ActionCall($action, $args)]);
    }

    public function runActions(string $entity, EntityId $id, array $actions): MutationResult
    {
        if ([] === $actions) {
            throw new InvalidArgumentException('A mutation must request at least one action.');
        }
        $existing = $this->load($entity, $id);

        if (null === $existing) {
            throw new RuntimeException(sprintf('There is no %s with id %s.', $entity, $id));
        }

        $decoded = [];
        foreach ($actions as $action) {
            $decoded[] = new ActionCall($action->name, $this->catalogue->decodeActionArguments($entity, $action->name, $action->arguments));
        }

        $mutation = new Mutation($entity, $id, $this->valuesOf($entity, $existing), $decoded, $existing);
        $mutator = $this->catalogue->mutatorFor($entity, $mutation);

        foreach ($decoded as $action) {
            if (!method_exists($mutator, $action->name)) {
                throw new RuntimeException(sprintf('%s declares no action "%s".', $entity, $action->name));
            }
            $this->writes->permit($entity, $existing, PendingWrite::forAction($entity, $action->name, $action->arguments, $mutation));
        }
        foreach ($decoded as $action) {
            $mutator->{$action->name}(...array_values($action->arguments));
        }

        $work = $this->units->create();
        $work->register($mutation);
        $work->commit();

        return $this->result($entity, $id);
    }

    private function result(string $entity, EntityId $id): MutationResult
    {
        $object = $this->load($entity, $id);
        if (null !== $object) {
            try {
                $object = $this->reads->permit($entity, $object);
            } catch (AccessDenied) {
                $object = null;
            }
        }

        return new MutationResult($id, $object);
    }

    public function has(string $entity): bool
    {
        return in_array($entity, $this->catalogue->entities(), true);
    }

    public function get(string $entity): Hydrator
    {
        return $this->catalogue->hydrator($entity);
    }

    /**
     * A query builder for hand-written finders.
     *
     * The application's own query classes need one, and building it means knowing
     * which four things a lazy query is made of. This is the assembled framework, so
     * it is the thing that already knows.
     */
    public function queries(): Queries
    {
        return new Queries($this->storage, $this->edges(), $this->reads);
    }

    private function edges(): CachingEdgeLoader
    {
        return new CachingEdgeLoader($this->storage, $this, $this->catalogue->edgeTargets(), $this->reads);
    }

    private function load(string $entity, EntityId $id): ?object
    {
        $record = $this->storage->get($entity, $id);

        return null === $record ? null : $this->get($entity)->hydrate($record, $this->edges());
    }

    /**
     * The row as it stands, so a verifier can compare against what was there.
     *
     * @return array<string, mixed>
     */
    private function valuesOf(string $entity, object $existing): array
    {
        $values = [];

        foreach ($this->catalogue->fieldNames($entity) as $field) {
            $getter = 'get' . ucfirst($field);

            if (method_exists($existing, $getter)) {
                $values[$field] = $existing->{$getter}();
            }
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function named(string $entity, string $query, array $args): array
    {
        $ordered = [];

        foreach ($this->catalogue->queryArguments($entity, $query) as $name) {
            $ordered[$name] = $args[$name] ?? null;
        }

        return $ordered;
    }
}
