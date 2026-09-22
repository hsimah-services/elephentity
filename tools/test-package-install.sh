#!/usr/bin/env bash
# Verify the actual Composer consumer layout, independently of root dev autoload.
set -euo pipefail
source_root="$(cd "$(dirname "$0")/.." && pwd)"
project="$(mktemp -d)"
trap 'rm -rf "$project"' EXIT
cd "$project"
cat > composer.json <<'JSON'
{
  "name": "elephentity/package-install-check",
  "require": {
    "elephentity/elephentity": "^0.11",
    "elephentity/memory": "^0.11"
  },
  "require-dev": {
    "elephentity/cli": "^0.11",
    "elephentity/codegen-php": "^0.6"
  },
  "autoload": {"psr-4": {"InstallCheck\\Entity\\": "generated/"}}
}
JSON
if [ "${1:-}" != --published ]; then
  # Paths model the future release without installing the monorepo as a library.
  php -r '
    $file = "composer.json";
    $config = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    foreach (["elephentity" => "", "schema" => "/packages/schema", "cli" => "/packages/cli", "memory" => "/packages/memory"] as $name => $path) {
        $config["repositories"][] = ["type" => "path", "url" => $argv[1] . $path,
            "options" => ["symlink" => false, "versions" => ["elephentity/" . $name => "0.11.0"]]];
    }
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  ' "$source_root"
fi
composer update --no-interaction --prefer-dist
cargo build --release --locked --manifest-path vendor/elephentity/codegen/Cargo.toml
cargo build --release --locked --manifest-path vendor/elephentity/codegen-php/Cargo.toml
mkdir -p spec/entities
cat > spec/project.yml <<'YAML'
project: InstallCheck
storage:
  driver: memory
YAML
cat > spec/entities/Note.yml <<'YAML'
entity: Note
storage:
  table: note
fields:
  body:
    type: text
    required: true
YAML
cat > eleph.json <<'JSON'
{
  "spec": "spec",
  "targets": {
    "php": {"builder": "vendor/bin/eleph-gen-php", "output": "generated", "namespace": "InstallCheck\\Entity", "typeNamespace": "InstallCheck\\Type"},
    "memory": {"builder": "vendor/bin/eleph-gen-memory", "output": "generated/memory"}
  }
}
JSON
vendor/bin/eleph fmt
vendor/bin/eleph validate
vendor/bin/eleph generate
vendor/bin/eleph generate --check
vendor/bin/eleph check
php -r '
    require "vendor/autoload.php";
    foreach (["Eleph\\Memory\\MemoryAdaptor", "InstallCheck\\Entity\\Note\\Note"] as $class) {
        if (!class_exists($class)) { throw new RuntimeException("Missing " . $class); }
    }
    foreach (["elephentity/wordpress", "elephentity/wpgraphql"] as $package) {
        if (Composer\InstalledVersions::isInstalled($package)) { throw new RuntimeException("Unexpected " . $package); }
    }
    if (is_dir("vendor/elephentity/elephentity")) { throw new RuntimeException("Metapackage installed source files"); }
'
composer install --no-dev --no-interaction
php -r '
    require "vendor/autoload.php";
    if (!class_exists("Eleph\\Memory\\MemoryAdaptor")) { throw new RuntimeException("Missing production adaptor"); }
    foreach (["Eleph\\Schema\\SchemaCompiler", "Eleph\\Cli\\Command\\GenerateCommand"] as $class) {
        if (class_exists($class)) { throw new RuntimeException("Build-time class shipped: " . $class); }
    }
'
printf 'Standalone install, generation, conformance and production dependency checks passed.\n'
