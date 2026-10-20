<?php

declare(strict_types=1);
require __DIR__ . '/support.php';

use Eleph\SQLite\Database;
use Eleph\SQLite\SQLiteAdaptor;
use Eleph\SQLite\Sql\FieldMap;
use Eleph\SQLite\Sql\QueryCompiler;
use Eleph\Runtime\Storage\Testing\AdaptorConformance;

foreach (['clog', 'relations', 'one-to-one', 'many-to-one', 'one-to-many', 'many-to-many'] as $case) {
    generated(fixture($case), function (string $directory) use ($case): void {
        $db = new Database(':memory:');
        $install = require $directory . '/install.php';
        $install($db->pdo, 'test_');
        $manifest = (require $directory . '/storage-manifest.php')->withPrefix('test_');
        foreach ($manifest->everyTable() as $table) {
            $columns = $db->select('PRAGMA table_info(' . Database::identifier($table->name) . ')');
            check(array_column($columns, 'name') === array_keys($table->columns), 'Manifest columns differ from installed columns: ' . $table->name);
            foreach ($columns as $column) check($column['type'] === $table->columns[$column['name']]->type, 'SQLite type mismatch');
        }
        check($db->select('PRAGMA foreign_key_check') === [], 'Invalid generated foreign keys');
        $before = $db->select('SELECT name, sql FROM sqlite_master ORDER BY name');
        fails(fn () => $install($db->pdo, 'test_'), 'Installer must refuse existing tables');
        check($before === $db->select('SELECT name, sql FROM sqlite_master ORDER BY name'), 'Repeated install changed existing schema');
        fails(fn () => $install($db->pdo, 'bad"prefix'), 'Invalid prefix accepted');
        if (isset($manifest->tables['Owner'])) {
            $adapter = new SQLiteAdaptor($db, $manifest->tables, new FieldMap($manifest->columns), $manifest->placements, new QueryCompiler(placements: $manifest->placements));
            $failures = (new AdaptorConformance())->check($adapter, 'Owner', 'target', 'Target');
            check($failures === [], $case . ': ' . implode('; ', $failures));
        }
        echo "PASS: generated $case schema, prefix, manifest and runtime\n";
    });
}
$request = fixture('many-to-one');
$request['schema']['entities']['Owner']['fields']['name']['unique'] = true;
$request['schema']['entities']['Owner']['fields']['name']['maxLength'] = 5;
$request['schema']['entities']['Owner']['fields']['rank']['type']['primitive'] = 'bool';
$request['schema']['entities']['Target']['fields']['name']['type']['primitive'] = 'enum';
$request['schema']['entities']['Target']['fields']['name']['enum'] = ['inlineValues' => ['plain', "quote'\"{prefix}\\"], 'declaredType' => null];
generated($request, function (string $directory): void {
    $db = new Database(':memory:'); $install = require $directory . '/install.php'; $install($db->pdo);
    $db->insert('target', ['name' => "quote'\"{prefix}\\"]);
    $id = $db->insert('owner', ['name' => 'first', 'rank' => 1]);
    fails(fn () => $db->insert('owner', ['name' => 'first']), 'Generated unique index did not reject duplicate');
    fails(fn () => $db->insert('owner', ['name' => 'longer']), 'Generated maximum length check did not reject input');
    fails(fn () => $db->insert('owner', ['rank' => 2]), 'Generated boolean check did not reject input');
    fails(fn () => $db->insert('target', ['name' => 'unknown']), 'Generated enum check did not reject input');
    fails(fn () => $db->insert('owner', ['target_id' => 999]), 'Generated FK did not reject a missing target');
    $db->execute('UPDATE owner SET name = ? WHERE id = ?', [null, $id]);
    $db->insert('owner', ['name' => null]);
    check($db->scalar('SELECT name FROM owner WHERE id = ?', [$id]) === null, 'Nullable fields must preserve NULL');
    $db->transaction(function () use ($db, $install): void {
        fails(fn () => $install($db->pdo, 'nested_'), 'Installer must reject a surrounding transaction');
    });
    $partial = new PDO('sqlite::memory:');
    $partial->exec('CREATE TABLE target (existing TEXT)');
    fails(fn () => $install($partial), 'Installer must fail on a partially existing schema');
    check($partial->query("SELECT count(*) FROM sqlite_master WHERE name='owner'")->fetchColumn() === 0, 'Failed installation did not roll back newly created tables');
    echo "PASS: generated constraints, hostile literals, NULL, foreign keys and installation rollback\n";
});

require __DIR__ . '/fixtures/PostStatus.php';
$request = fixture('many-to-one');
$request['schema']['types']['PostStatus'] = ['name' => 'PostStatus', 'primitive' => 'int', 'values' => ['draft', 'scheduled', 'published'], 'sourceFile' => 'status.yml'];
$request['schema']['entities']['Target']['fields']['name']['type'] = ['primitive' => null, 'declaredType' => 'PostStatus'];
$request['schema']['entities']['Target']['fields']['name']['enum'] = ['inlineValues' => null, 'declaredType' => 'PostStatus'];
foreach ([false, true] as $enumPrimitive) {
    if ($enumPrimitive) $request['schema']['entities']['Target']['fields']['name']['type']['primitive'] = 'enum';
    generated($request, function (string $directory): void {
        $db = new Database(':memory:');
        $install = require $directory . '/install.php'; $install($db->pdo);
        $manifest = require $directory . '/storage-manifest.php';
        $adapter = new SQLiteAdaptor($db, $manifest->tables, new FieldMap($manifest->columns), $manifest->placements);
        $encoder = new \Eleph\Runtime\UnitOfWork\ValueEncoder([], new \Eleph\Runtime\Type\NullProcessorRegistry());
        foreach (\App\Elephentity\Enum\PostStatus::cases() as $case) {
            $pending = new \Eleph\Runtime\Identity\PendingId('Target');
            $value = $encoder->encode('Target', 'name', $case);
            $result = $adapter->write(new \Eleph\Runtime\Storage\Write\WriteBatch(new \Eleph\Runtime\Storage\Write\Insert('Target', $pending, ['name' => $value])));
            $id = $result->idFor($pending);
            check($adapter->get('Target', $id)->value('name') === $case->value, 'Generated PHP integer enum backing value was not stored');
            $adapter->write(new \Eleph\Runtime\Storage\Write\WriteBatch(new \Eleph\Runtime\Storage\Write\Update('Target', $id, ['name' => 2])));
        }
        fails(fn () => $db->insert('target', ['name' => 'draft']), 'Integer enum must reject domain labels');
        fails(fn () => $db->insert('target', ['name' => -1]), 'Integer enum must reject negative ordinals');
        fails(fn () => $db->insert('target', ['name' => 3]), 'Integer enum must reject out-of-range ordinals');
        echo "PASS: generated PHP integer enums through SQLite schema and adapter\n";
    });
}
