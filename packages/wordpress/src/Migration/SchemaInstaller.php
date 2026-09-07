<?php

declare(strict_types=1);

namespace Eleph\WordPress\Migration;

use Eleph\WordPress\Database\Database;
use Eleph\WordPress\Manifest\StorageManifest;
use Eleph\WordPress\Sql\TableSchema;

/**
 * Brings the database up to what the manifest describes.
 *
 * The entry point that was missing. `MigrationPlanner` and `DdlCompiler` both existed
 * and nothing reached them, so a project's first act was to hand-roll a walk over the
 * manifest — which creates tables and cannot migrate them, and silently misses the
 * join table behind a many-to-many edge.
 *
 * It lives at runtime rather than in `eleph`, and that is not a compromise. Migrating
 * means diffing against a live database; the CLI runs at build time with no WordPress
 * loaded and no credentials, so a build-time `migrate` could only ever have emitted a
 * fresh install's DDL and called it a migration. Plugin activation is where the
 * database is, so that is where this belongs.
 *
 * Additive changes are applied. Anything destructive or ambiguous is refused, and the
 * refusals name themselves — see MigrationPlanner for why a diff cannot infer intent.
 */
final readonly class SchemaInstaller
{
    public function __construct(
        private Database $database,
        private StorageManifest $manifest,
        private MigrationPlanner $planner = new MigrationPlanner(),
        private Introspector $introspector = new Introspector(),
    ) {
    }

    /**
     * What would change, without changing it.
     *
     * Worth having on its own: an admin screen or a CLI wrapper can show the plan, and
     * a refusal is a thing to read rather than a thing to catch.
     */
    public function plan(): MigrationPlan
    {
        $desired = $this->manifest->withPrefix($this->database->prefix())->everyTable();
        $current = [];

        foreach ($desired as $name => $table) {
            $existing = $this->introspector->inspect($this->database, $name);

            if (null !== $existing) {
                $current[$name] = $existing;
            }
        }

        // Only the tables this schema knows about. The planner refuses an unexpected
        // table, and every other plugin's tables share the installation's prefix —
        // reporting those would make every plan a wall of refusals about tables that
        // are none of our business.
        return $this->planner->plan($desired, $current);
    }

    /**
     * Apply what can be applied, and report what could not.
     *
     * **Nothing is applied when anything is refused.** A half-migrated schema is worse
     * than an unmigrated one: the application boots, some queries work, and the reason
     * is now two states away from the spec. The plan comes back either way, so a caller
     * can log the refusals or fail activation on them.
     */
    public function install(): MigrationPlan
    {
        $plan = $this->plan();

        if (!$plan->isSafe()) {
            return $plan;
        }

        foreach ($plan->statements as $statement) {
            $this->database->execute($statement);
        }

        return $plan;
    }

    /**
     * The DDL for a schema that does not exist yet.
     *
     * For printing, for a migration file, for a test fixture — anywhere the statements
     * are wanted without a database to run them against.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        return (new MigrationPlanner())->plan(
            $this->manifest->withPrefix($this->database->prefix())->everyTable(),
            [],
        )->statements;
    }

    /**
     * @return array<string, TableSchema>
     */
    public function tables(): array
    {
        return $this->manifest->withPrefix($this->database->prefix())->everyTable();
    }
}
