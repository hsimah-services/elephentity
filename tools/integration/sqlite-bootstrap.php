<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Keep the optional adapter out of the core package's production dependencies.
$sqliteSource = getenv('ELEPH_SQLITE_SOURCE') ?: dirname(__DIR__, 3) . '/elephentity-sqlite/src';
if (!is_file($sqliteSource . '/SQLiteAdaptor.php')) {
    throw new RuntimeException('Set ELEPH_SQLITE_SOURCE to the SQLite package src directory.');
}
spl_autoload_register(static function (string $class) use ($sqliteSource): void {
    $prefix = 'Eleph\\SQLite\\';
    if (str_starts_with($class, $prefix)) {
        require $sqliteSource . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
