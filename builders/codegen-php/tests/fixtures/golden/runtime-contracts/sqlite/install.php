<?php
declare(strict_types=1);
/**
 * Explicit initial installation. Existing tables cause a failure and rollback.
 * Evolving a populated database requires a reviewed application migration.
 * Use the same prefix when loading StorageManifest::withPrefix().
 */
return static function (\PDO $database, string $prefix = ''): void {
    if ($database->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new \InvalidArgumentException('This installer requires PDO SQLite.');
    }
    if ($prefix !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $prefix)) {
        throw new \InvalidArgumentException('Invalid SQLite table prefix.');
    }
    if ($database->inTransaction()) {
        throw new \LogicException('Run the initial installer outside an existing transaction.');
    }
    $database->exec('PRAGMA foreign_keys = ON');
    $table = static fn (string $name): string => '"' . $prefix . $name . '"';
    $statements = [
        'CREATE TABLE ' . $table('page') . ' (
    ' . '"id" INTEGER PRIMARY KEY AUTOINCREMENT' . ',
    ' . '"title" TEXT NOT NULL' . ',
    ' . '"slug" TEXT NOT NULL' . ',
    ' . '"content" TEXT NOT NULL' . ',
    ' . '"excerpt" TEXT NOT NULL' . ',
    ' . '"status" TEXT NOT NULL CHECK ("status" IN (\'draft\', \'published\'))' . ',
    ' . '"published_at" TEXT' . ',
    ' . '"enquiry_url" TEXT' . ',
    ' . '"active" INTEGER NOT NULL CHECK ("active" IN (0, 1))' . ',
    ' . '"zero" INTEGER NOT NULL' . ',
    ' . '"disabled" INTEGER NOT NULL CHECK ("disabled" IN (0, 1))' . ',
    ' . '"metadata" TEXT NOT NULL' . ',
    ' . '"created_at" TEXT NOT NULL' . ',
    ' . '"rank" INTEGER NOT NULL' . ',
    ' . '"amount" TEXT NOT NULL' . '
)',
        'CREATE TABLE ' . $table('user') . ' (
    ' . '"id" INTEGER PRIMARY KEY AUTOINCREMENT' . ',
    ' . '"username" TEXT NOT NULL' . ',
    ' . '"role" INTEGER NOT NULL CHECK ("role" IN (0, 1))' . ',
    ' . '"active" INTEGER NOT NULL CHECK ("active" IN (0, 1))' . '
)',
    ];
    $database->beginTransaction();
    try {
        foreach ($statements as $sql) {
            if ($database->exec($sql) === false) {
                $detail = $database->errorInfo()[2] ?? null;
                throw new \RuntimeException('SQLite schema installation failed: ' . (is_string($detail) ? $detail : 'unknown error'));
            }
        }
        $database->commit();
    } catch (\Throwable $error) {
        $database->rollBack();
        throw $error;
    }
};
