<?php

declare(strict_types=1);

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Mutation\Deletion;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\Storage\DeletionPolicy;
use Eleph\Runtime\Storage\DeletionRule;
use Eleph\Runtime\Storage\DeletionRules;
use Eleph\Runtime\Storage\RelationKind;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\WriteBatch;
use Eleph\Runtime\Type\NullProcessorRegistry;
use Eleph\Runtime\UnitOfWork\DeletionPlanner;
use Eleph\Runtime\UnitOfWork\SideEffectDispatcher;
use Eleph\Runtime\UnitOfWork\UnitOfWork;
use Eleph\Runtime\UnitOfWork\ValueEncoder;
use Eleph\Runtime\UnitOfWork\VerificationPipeline;
use Eleph\SQLite\Database;
use Eleph\SQLite\Sql\Column;
use Eleph\SQLite\Sql\EdgePlacement;
use Eleph\SQLite\Sql\FieldMap;
use Eleph\SQLite\Sql\QueryCompiler;
use Eleph\SQLite\Sql\TableSchema;
use Eleph\SQLite\SQLiteAdaptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeletionPlannerSQLiteTest extends TestCase
{
    public static function policies(): iterable
    {
        foreach (RelationKind::cases() as $kind) {
            foreach (DeletionPolicy::cases() as $policy) {
                yield $kind->name . '/' . $policy->name => [$kind, $policy, false];
                if ($kind->needsJoinTable()) {
                    yield $kind->name . '/target/' . $policy->name => [$kind, $policy, true];
                }
            }
        }
    }

    #[DataProvider('policies')]
    public function testPoliciesUseTheActualDirection(RelationKind $kind, DeletionPolicy $policy, bool $joinTarget): void
    {
        $db = new Database(':memory:');
        $db->pdo->exec('CREATE TABLE owner (id INTEGER PRIMARY KEY, target_id INTEGER REFERENCES target(id));
            CREATE TABLE target (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owner(id));
            CREATE TABLE owner_target (owner_id INTEGER NOT NULL REFERENCES owner(id), target_id INTEGER NOT NULL REFERENCES target(id), UNIQUE(owner_id, target_id));');
        if (RelationKind::OneToOne === $kind) {
            $db->pdo->exec('CREATE UNIQUE INDEX one_target ON owner(target_id)');
        }
        $placement = new EdgePlacement(
            'Owner',
            'edge',
            'Target',
            $kind,
            $kind->needsJoinTable() ? 'owner_target' : ($kind->keyIsLocal() ? 'owner' : 'target'),
            $kind->keyIsLocal() ? 'target_id' : 'owner_id',
            $kind->needsJoinTable() ? 'target_id' : null,
            'target',
        );
        $storage = $this->storage($db, ['Owner' => 'owner', 'Target' => 'target'], [$placement]);
        $db->pdo->exec('INSERT INTO owner(id) VALUES (5),(9),(20),(21),(99); INSERT INTO target(id) VALUES (5),(9),(20),(21),(99)');
        $reverse = $kind->keyIsLocal() || $joinTarget;
        $parent = $reverse ? 'Target' : 'Owner';
        $dependent = $reverse ? 'Owner' : 'Target';
        $ids = RelationKind::OneToOne === $kind ? [20] : [20, 21];
        if ($kind->needsJoinTable()) {
            $db->pdo->exec($joinTarget
                ? 'INSERT INTO owner_target VALUES (20,5),(21,5),(5,9)'
                : 'INSERT INTO owner_target VALUES (5,20),(5,21),(9,5)');
            // A surviving dependent's unrelated link must survive nullification.
            if (DeletionPolicy::Nullify === $policy) {
                $db->pdo->exec($joinTarget
                    ? 'INSERT INTO owner_target VALUES (20,9)'
                    : 'INSERT INTO owner_target VALUES (9,20)');
            }
        } elseif ($kind->keyIsLocal()) {
            $db->pdo->exec('UPDATE owner SET target_id=9 WHERE id=5');
            foreach ($ids as $id) {
                $db->pdo->exec("UPDATE owner SET target_id=5 WHERE id=$id");
            }
        } else {
            $db->pdo->exec('UPDATE target SET owner_id=9 WHERE id=5; UPDATE target SET owner_id=5 WHERE id IN (20,21)');
        }
        // These are the rules emitted by the PHP builder: local keys depend on
        // the target, remote keys on the owner, and joins have rules on both ends.
        $rules = $kind->needsJoinTable() ? [
            'Owner' => [new DeletionRule('Target', 'edge', 'Owner', $policy, true)],
            'Target' => [new DeletionRule('Owner', 'edge', 'Owner', $policy, true)],
        ] : [$parent => [new DeletionRule($dependent, 'edge', 'Owner', $policy)]];
        $planner = $this->planner($storage, $rules);
        $before = $this->snapshot($db, ['owner', 'target', 'owner_target']);
        if (DeletionPolicy::Restrict === $policy) {
            try {
                $work = $this->work($storage, $planner);
                $work->delete(new Deletion($parent, EntityId::of(5)));
                $work->commit();
                self::fail('Occupied parent must be restricted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('edge says restrict', $error->getMessage());
            }
            self::assertSame($before, $this->snapshot($db, ['owner', 'target', 'owner_target']));
            // An unoccupied row can still be removed.
            $storage->write(new WriteBatch(...$planner->plan([new Deletion($parent, EntityId::of(99))])));
        } else {
            $operations = $planner->plan([new Deletion($parent, EntityId::of(5))]);
            self::assertEquals(new Delete($parent, EntityId::of(5)), $operations[array_key_last($operations)]);
            $deletes = array_values(array_filter($operations, static fn ($op) => $op instanceof Delete));
            self::assertCount(DeletionPolicy::Cascade === $policy ? count($ids) + 1 : 1, $deletes);
            if (DeletionPolicy::Cascade === $policy) {
                self::assertEquals(array_map(static fn ($id) => new Delete($dependent, EntityId::of($id)), $ids), array_slice($deletes, 0, -1));
            }
            if ($reverse && (DeletionPolicy::Nullify === $policy || $kind->needsJoinTable())) {
                self::assertEquals(new Unlink('Owner', 'edge', EntityId::of(20), EntityId::of(5)), $operations[0]);
            }
            $work = $this->work($storage, $planner);
            $work->delete(new Deletion($parent, EntityId::of(5)));
            $work->commit();
            self::assertNull($storage->get($parent, EntityId::of(5)));
            foreach ($ids as $id) {
                self::assertSame(DeletionPolicy::Cascade === $policy, null === $storage->get($dependent, EntityId::of($id)));
            }
            self::assertNotNull($storage->get($dependent, EntityId::of(5)), 'Colliding unrelated ID survives');
            self::assertNotNull($storage->get($parent, EntityId::of(9)));
            if ($kind->needsJoinTable()) {
                self::assertSame(DeletionPolicy::Nullify === $policy ? 2 : 1, (int) $db->scalar('SELECT COUNT(*) FROM owner_target'));
            } else {
                $table = $kind->keyIsLocal() ? 'owner' : 'target';
                $column = $kind->keyIsLocal() ? 'target_id' : 'owner_id';
                self::assertSame(9, (int) $db->scalar("SELECT $column FROM $table WHERE id=5"));
                if (DeletionPolicy::Nullify === $policy) {
                    self::assertNull($db->scalar("SELECT $column FROM $table WHERE id=20"));
                }
            }
        }
        self::assertSame([], $db->select('PRAGMA foreign_key_check'));
    }

    public function testClogCascadeChildDeletionAndLocationRestriction(): void
    {
        foreach (['item', 'child', 'occupied', 'empty', 'rollback'] as $scenario) {
            [$db, $storage, $planner] = $this->clog();
            $before = $this->snapshot($db, ['item', 'inventory', 'location']);
            $work = $this->work($storage, $planner);
            if ('item' === $scenario) {
                self::assertEquals([
                    new Delete('Inventory', EntityId::of(20)),
                    new Delete('Inventory', EntityId::of(21)),
                    new Delete('Item', EntityId::of(5)),
                ], $planner->plan([new Deletion('Item', EntityId::of(5))]));
                $work->delete(new Deletion('Item', EntityId::of(5)));
                $work->commit();
                self::assertSame([5], array_column($db->select('SELECT id FROM inventory'), 'id'));
                self::assertNotNull($storage->get('Item', EntityId::of(9)));
                self::assertSame($before['location'], $db->select('SELECT * FROM location ORDER BY id'));
            } elseif ('child' === $scenario) {
                $work->delete(new Deletion('Inventory', EntityId::of(20)));
                $work->commit();
                self::assertSame([5, 21], array_column($db->select('SELECT id FROM inventory ORDER BY id'), 'id'));
                self::assertSame($before['item'], $db->select('SELECT * FROM item ORDER BY id'));
                self::assertSame($before['location'], $db->select('SELECT * FROM location ORDER BY id'));
            } elseif ('empty' === $scenario) {
                $work->delete(new Deletion('Location', EntityId::of(99)));
                $work->commit();
                self::assertNull($storage->get('Location', EntityId::of(99)));
            } else {
                if ('rollback' === $scenario) {
                    // Initially empty, then occupied by a pending mutation. The
                    // second plan must reject inside the write transaction.
                    $mutation = new Mutation('Inventory', EntityId::of(20));
                    $mutation->set('name', 'must roll back');
                    $mutation->edge('location')->add(EntityId::of(99));
                    $work->register($mutation);
                }
                $work->delete(new Deletion('Location', EntityId::of('rollback' === $scenario ? 99 : 7)));
                try {
                    $work->commit();
                    self::fail('Occupied location must be restricted');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('edge says restrict', $error->getMessage());
                }
                self::assertSame($before, $this->snapshot($db, ['item', 'inventory', 'location']));
            }
            self::assertSame([], $db->select('PRAGMA foreign_key_check'));
        }
    }

    public function testDeletingTheForeignKeyHolderDoesNotDeleteItsParent(): void
    {
        foreach ([RelationKind::OneToOne, RelationKind::ManyToOne, RelationKind::OneToMany] as $kind) {
            $db = new Database(':memory:');
            $db->pdo->exec('CREATE TABLE parent (id INTEGER PRIMARY KEY);
                CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent(id));
                INSERT INTO parent VALUES (5),(9); INSERT INTO child VALUES (20,5),(5,9)');
            if (RelationKind::OneToOne === $kind) {
                $db->pdo->exec('CREATE UNIQUE INDEX one_parent ON child(parent_id)');
            }
            $declaredBy = $kind->keyIsLocal() ? 'Child' : 'Parent';
            $placement = new EdgePlacement(
                $declaredBy,
                'edge',
                $kind->keyIsLocal() ? 'Parent' : 'Child',
                $kind,
                'child',
                'parent_id',
                targetTable: $kind->keyIsLocal() ? 'parent' : 'child',
            );
            $storage = $this->storage($db, ['Parent' => 'parent', 'Child' => 'child'], [$placement]);
            $planner = $this->planner($storage, [
                'Parent' => [new DeletionRule('Child', 'edge', $declaredBy, DeletionPolicy::Cascade)],
            ]);
            self::assertEquals([new Delete('Child', EntityId::of(20))], $planner->plan([new Deletion('Child', EntityId::of(20))]));
            $work = $this->work($storage, $planner);
            $work->delete(new Deletion('Child', EntityId::of(20)));
            $work->commit();
            self::assertSame([5, 9], array_column($db->select('SELECT id FROM parent ORDER BY id'), 'id'));
            self::assertSame([5], array_column($db->select('SELECT id FROM child'), 'id'));
            self::assertSame([], $db->select('PRAGMA foreign_key_check'));
        }
    }

    private function clog(): array
    {
        $db = new Database(':memory:');
        $db->pdo->exec("CREATE TABLE item (id INTEGER PRIMARY KEY);
            CREATE TABLE location (id INTEGER PRIMARY KEY);
            CREATE TABLE inventory (id INTEGER PRIMARY KEY, name TEXT, item_id INTEGER NOT NULL REFERENCES item(id), location_id INTEGER NOT NULL REFERENCES location(id));
            INSERT INTO item VALUES (5),(9); INSERT INTO location VALUES (7),(99);
            INSERT INTO inventory VALUES (20,'first',5,7),(21,'second',5,7),(5,'unrelated',9,7)");
        $storage = $this->storage($db, ['Item' => 'item', 'Inventory' => 'inventory', 'Location' => 'location'], [
            new EdgePlacement('Inventory', 'item', 'Item', RelationKind::ManyToOne, 'inventory', 'item_id', targetTable: 'item'),
            new EdgePlacement('Inventory', 'location', 'Location', RelationKind::ManyToOne, 'inventory', 'location_id', targetTable: 'location'),
        ]);
        return [$db, $storage, $this->planner($storage, [
            'Item' => [new DeletionRule('Inventory', 'item', 'Inventory', DeletionPolicy::Cascade)],
            'Location' => [new DeletionRule('Inventory', 'location', 'Inventory', DeletionPolicy::Restrict)],
        ])];
    }

    private function storage(Database $db, array $tables, array $edges): SQLiteAdaptor
    {
        $schemas = [];
        foreach ($tables as $entity => $table) {
            $columns = [];
            foreach ($db->select("PRAGMA table_info($table)") as $column) {
                $columns[$column['name']] = new Column($column['name'], $column['type']);
            }
            $schemas[$entity] = new TableSchema($table, $columns);
        }
        $placements = [];
        foreach ($edges as $edge) {
            $placements[$edge->entity . '.' . $edge->edge] = $edge;
        }
        return new SQLiteAdaptor($db, $schemas, new FieldMap([]), $placements, new QueryCompiler(placements: $placements));
    }

    private function planner(SQLiteAdaptor $storage, array $rules): DeletionPlanner
    {
        return new DeletionPlanner($storage, new class ($rules) implements DeletionRules {
            public function __construct(private readonly array $rules)
            {
            }
            public function for(string $entity): array
            {
                return $this->rules[$entity] ?? [];
            }
        });
    }

    private function work(SQLiteAdaptor $storage, DeletionPlanner $planner): UnitOfWork
    {
        $processors = new NullProcessorRegistry();
        return new UnitOfWork($storage, new VerificationPipeline([], [], $processors), new ValueEncoder([], $processors), new SideEffectDispatcher([]), planner: $planner);
    }

    private function snapshot(Database $db, array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = $db->select("SELECT * FROM $table ORDER BY 1");
        }
        return $rows;
    }
}
