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
